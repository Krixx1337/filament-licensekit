<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LicenseKit\Core\Contracts\DeviceMatcher;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LoaderSession;
use LicenseKit\Core\Models\Product;
use LicenseKit\Core\Users\ConfiguredUserModel;
use LicenseKit\Core\Users\UserAccess;

class LoaderSessions
{
    public function __construct(
        private AccessPolicy $access,
        private LicensedDevices $devices,
        private DeviceMatcher $matcher,
        private DeviceBans $bans,
        private LicenseClock $clock,
        private UserAccess $users,
        private ClaimLicense $claims,
    ) {}

    /** @return array<string, mixed> */
    public function login(Product $product, string $login, #[\SensitiveParameter] string $password, string $device, bool $move = false): array
    {
        $provider = Auth::createUserProvider(ConfiguredUserModel::provider());
        $credentials = [(string) config('licensekit.client_login_column') => $login, 'password' => $password];
        $user = $provider?->retrieveByCredentials($credentials);
        if (! $user instanceof Model || ! $provider->validateCredentials($user, $credentials)) {
            throw new LicensingException('invalid_credentials', 401);
        }
        $fingerprint = $this->fingerprint($user);

        return DB::transaction(function () use ($product, $user, $fingerprint, $device, $move): array {
            $user = $user->fresh() ?? throw new LicensingException('invalid_credentials', 401);
            if (! hash_equals($fingerprint, $this->fingerprint($user))) {
                throw new LicensingException('invalid_credentials', 401);
            }
            $this->assertAccount($user);
            $this->bans->assertAllowed($product, $device);
            $license = $this->lockedLicense($user, $product);
            $this->access->assertAllowed($user, $product);
            if ($move) {
                $this->devices->moveDevice($user, $product, $device);
            } else {
                $this->assertDevice($product, $license, $device);
            }
            $result = $this->accessResult($user, $product, $device);
            $token = bin2hex(random_bytes(32));
            $session = LoaderSession::create(['token_hash' => hash('sha256', $token), 'user_id' => $user->getKey(),
                'product_id' => $product->id, 'device' => $device, 'user_fingerprint' => $fingerprint,
                'expires_at' => now()->addMinutes(15), 'last_seen_at' => now()]);

            return [...$result, 'token' => $token, 'expires_at' => $session->expires_at->toIso8601String(), 'heartbeat_seconds' => 60];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function heartbeat(Product $product, #[\SensitiveParameter] string $token, string $device): array
    {
        return DB::transaction(function () use ($product, $token, $device): array {
            [$session, $user] = $this->session($product, $token, $device);
            $result = $this->accessResult($user, $product, $device);
            $session->update(['last_seen_at' => now(), 'expires_at' => now()->addMinutes(15)]);

            return [...$result, 'expires_at' => $session->expires_at->toIso8601String(), 'heartbeat_seconds' => 60];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function redeem(Product $product, #[\SensitiveParameter] string $token, string $key): array
    {
        return DB::transaction(function () use ($product, $token, $key): array {
            [$session, $user] = $this->session($product, $token);
            $candidate = LicenseKey::where('key', app(SerialKey::class)->normalize($key))->first();
            if ($candidate === null || $candidate->batch->plan->product_id !== $product->id) {
                throw new LicensingException('invalid_key', 422);
            }
            $this->claims->claim($user, $key);

            return $this->accessResult($user, $product, $session->device);
        }, 3);
    }

    /** @return array{0: LoaderSession, 1: Model} */
    private function session(Product $product, string $token, ?string $device = null): array
    {
        $candidate = LoaderSession::where('token_hash', hash('sha256', $token))->where('product_id', $product->id)->first();
        if ($candidate === null || ! $candidate->expires_at->isFuture()) {
            throw new LicensingException('invalid_token', 401);
        }
        $user = ConfiguredUserModel::query()->whereKey($candidate->user_id)->first() ?? throw new LicensingException('invalid_token', 401);
        $license = $this->lockedLicense($user, $product);
        $session = LoaderSession::whereKey($candidate->id)->lockForUpdate()->first();
        if ($session === null || ! $session->expires_at->isFuture() || ! hash_equals($session->user_fingerprint, $this->fingerprint($user))) {
            throw new LicensingException('invalid_token', 401);
        }
        $this->assertAccount($user);
        $this->bans->assertAllowed($product, $session->device);
        $this->access->assertAllowed($user, $product);
        $this->assertDevice($product, $license, $session->device);
        if ($device !== null) {
            $this->bans->assertAllowed($product, $device);
            if (! $this->matcher->matches($product, $session->device, $device)) {
                throw new LicensingException('device_mismatch');
            }
        }

        return [$session, $user];
    }

    private function lockedLicense(Model $user, Product $product): ?License
    {
        $license = License::where('user_id', $user->getKey())->where('product_id', $product->id)->lockForUpdate()->first();
        if ($license !== null) {
            if ($license->status === License::SUSPENDED && ! $license->isSuspended()) {
                $license->update(['status' => $license->restingStatus(), 'suspended_until' => null]);
            }
            $this->clock->resumeWhenDue($license);
        }

        return $license;
    }

    private function assertDevice(Product $product, ?License $license, string $device): void
    {
        if (trim($device) === '' || strlen($device) > LicensedDevices::MAXIMUM_DEVICE_BYTES || ! mb_check_encoding($device, 'UTF-8')) {
            throw new LicensingException('invalid_device', 422);
        }
        if ($product->device_lock && $license?->device !== null && ! $this->matcher->matches($product, $license->device, $device)) {
            throw new LicensingException('device_mismatch', 403, ['can_move' => $this->devices->eligibility($license)['allowed'],
                'move_available_at' => $this->devices->eligibility($license)['available_at']]);
        }
    }

    /** @return array<string, mixed> */
    private function accessResult(Model $user, Product $product, string $device): array
    {
        $allowed = true;
        $code = null;
        $until = null;
        try {
            $context = $this->devices->bind($user, $product, $device);
            $until = $context->access->validUntil?->toIso8601String();
        } catch (LicensingException $exception) {
            $code = match ($exception->error) {
                'no_license' => 'no_license', 'expired' => 'expired', 'paused' => 'paused',
                default => throw $exception,
            };
            $allowed = false;
        }
        $license = License::where('user_id', $user->getKey())->where('product_id', $product->id)->first();

        return ['access' => $allowed, 'code' => $code, 'access_valid_until' => $until,
            'license' => $license === null ? null : ['status' => $license->status, 'expires_at' => $license->expires_at?->toIso8601String(), 'lifetime' => $license->is_lifetime]];
    }

    private function assertAccount(Model $user): void
    {
        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            throw new LicensingException('email_not_verified');
        }
        if (! $this->users->available($user)) {
            throw new LicensingException('user_unavailable');
        }
    }

    private function fingerprint(Model $user): string
    {
        $password = $user instanceof Authenticatable ? $user->getAuthPassword() : '';

        return hash_hmac('sha256', $password, (string) config('app.key'));
    }
}
