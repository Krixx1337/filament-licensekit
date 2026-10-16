<?php

namespace LicenseKit\Tests\Core\Fixtures;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use LicenseKit\Core\Actions\Audit;
use LicenseKit\Core\Actions\LicensedDevices;
use LicenseKit\Core\Contracts\DeviceMatcher;
use LicenseKit\Core\Data\AccessAuthorization;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\Product;
use LicenseKit\Core\Users\ConfiguredUserModel;

class ClientSessions
{
    public function __construct(private HostAuthenticateUser $credentials, private ClientSecurity $security, private LicensedDevices $devices, private DeviceMatcher $matcher, private Audit $audit) {}

    /**
     * @return array{token: string, expires_at: string, entitlement: Entitlement|null, access: array{source: string, valid_until: string|null}, heartbeat_seconds: int}
     */
    public function login(Product $product, string $identity, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $hwid): array
    {
        $this->requireConfigured();
        $authenticated = $this->credentials->authenticate($identity, $password, rehashPassword: false) ?? throw new LicensingException('invalid_credentials', 401);
        $fingerprint = $this->security->fingerprint($authenticated);

        return DB::transaction(function () use ($product, $authenticated, $fingerprint, $hwid): array {
            $user = ConfiguredUserModel::className()::whereKey($authenticated->getKey())->lockForUpdate()->first();
            if ($user === null || ! hash_equals($fingerprint, $this->security->fingerprint($user))) {
                throw new LicensingException('invalid_credentials', 401);
            }
            $context = $this->devices->bind($user, $product, $hwid, $this->freeUntil());
            $expiry = $this->expiry($context->access);
            $session = ClientSession::create(['user_id' => $user->getKey(), 'product_id' => $context->access->product->id, 'hwid' => $context->device, 'user_fingerprint' => $fingerprint]);
            $token = $session->createToken('desktop:'.$product->id, ['desktop:heartbeat:'.$product->id], $expiry);
            $session->update(['personal_access_token_id' => $token->accessToken->id]);
            $this->audit->record('user:'.$user->getKey(), 'client_login', $context->access->product);

            return ['token' => $token->plainTextToken, 'expires_at' => $expiry->toIso8601String(), 'entitlement' => $context->access->entitlement, 'access' => $context->access->summary(), 'heartbeat_seconds' => (int) config('host_client.heartbeat_seconds')];
        });
    }

    /**
     * @return array{expires_at: string, entitlement: Entitlement|null, access: array{source: string, valid_until: string|null}, heartbeat_seconds: int}
     */
    public function heartbeat(Product $product, #[\SensitiveParameter] string $bearer, #[\SensitiveParameter] string $hwid): array
    {
        $this->requireConfigured();

        return DB::transaction(function () use ($product, $bearer, $hwid): array {
            $token = Sanctum::personalAccessTokenModel()::findToken($bearer);
            if ($token === null || ! $token->tokenable instanceof ClientSession || $token->expires_at === null || $token->expires_at->lessThanOrEqualTo(now())
                || ! $token->can('desktop:heartbeat:'.$product->id) || $token->name !== 'desktop:'.$product->id) {
                throw new LicensingException('invalid_token', 401);
            }
            $session = $token->tokenable;
            $user = ConfiguredUserModel::className()::whereKey($session->user_id)->lockForUpdate()->first();
            $token = Sanctum::personalAccessTokenModel()::whereKey($token->id)->lockForUpdate()->first();
            $session = ClientSession::whereKey($session->id)->lockForUpdate()->first();
            if ($token === null || $session === null) {
                throw new LicensingException('invalid_token', 401);
            }
            if ($session->personal_access_token_id !== $token->id || $session->product_id !== $product->id || $user === null
                || ! hash_equals($session->user_fingerprint, $this->security->fingerprint($user))) {
                throw new LicensingException('invalid_token', 401);
            }
            $context = $this->devices->verify($user, $product, $hwid, $this->freeUntil());
            if (! $this->matcher->matches($context->access->product, $session->hwid, $context->device)) {
                throw new LicensingException('hwid_mismatch');
            }
            $expiry = $this->expiry($context->access);
            $token->update(['expires_at' => $expiry, 'last_used_at' => now()]);

            return ['expires_at' => $expiry->toIso8601String(), 'entitlement' => $context->access->entitlement, 'access' => $context->access->summary(), 'heartbeat_seconds' => (int) config('host_client.heartbeat_seconds')];
        });
    }

    public function terminate(Entitlement $entitlement, string $actor, string $reason): void
    {
        if (trim($reason) === '') {
            throw new LicensingException('reason_required', 422);
        }
        DB::transaction(function () use ($entitlement, $actor, $reason): void {
            $entitlement = $entitlement->fresh() ?? throw new LicensingException('license_unavailable');
            app(SessionCleanup::class)->entitlement($entitlement);
            $this->audit->record($actor, 'desktop_session_terminated', $entitlement, [], [], $reason);
        });
    }

    private function expiry(AccessAuthorization $access): CarbonImmutable
    {
        $expiry = CarbonImmutable::now()->addMinutes((int) config('host_client.session_minutes'));

        return $access->source === 'host_free' && $access->validUntil !== null && $access->validUntil->lessThan($expiry)
            ? $access->validUntil->setTimezone($expiry->getTimezone()) : $expiry;
    }

    private function freeUntil(): ?CarbonImmutable
    {
        $value = config('host_client.free_until');

        return $value === null ? null : CarbonImmutable::parse($value, 'UTC');
    }

    private function requireConfigured(): void
    {
        if (config('host_client.enabled') !== true) {
            throw new LicensingException('module_disabled', 503);
        }
        foreach (['session_minutes', 'heartbeat_seconds'] as $setting) {
            $value = config('host_client.'.$setting);
            if (! is_int($value) || $value < 1) {
                throw new LicensingException('client_unavailable', 503);
            }
        }
    }
}
