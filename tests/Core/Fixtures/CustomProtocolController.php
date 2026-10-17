<?php

namespace LicenseKit\Tests\Core\Fixtures;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\LicensedDevices;
use LicenseKit\Core\Events\AccessRevoked;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Product;

/**
 * A seller's own app protocol, built only on public LicenseKit services.
 * Own routes, field names, cached sessions and HMAC proofs; LicenseKit still runs every check.
 */
class CustomProtocolController
{
    public const SECRET = 'test-only-shared-secret';

    public function login(Request $request, Product $product, LicensedDevices $devices): Response
    {
        $data = $request->validate(['username' => 'required|string', 'password' => 'required|string', 'hwid' => 'required|string', 'move' => 'boolean']);
        $credentials = ['email' => $data['username'], 'password' => $data['password']];
        $provider = Auth::guard()->getProvider();
        $user = $provider->retrieveByCredentials($credentials);
        if ($user === null || ! $provider->validateCredentials($user, $credentials)) {
            return $this->reply($request, ['error' => 'bad_login'], 401);
        }
        try {
            if ($data['move'] ?? false) {
                $devices->moveDevice($user, $product, $data['hwid']);
            }
            $grant = $devices->bind($user, $product, $data['hwid']);
        } catch (LicensingException $exception) {
            return $this->reply($request, ['error' => $exception->error], $exception->status);
        }
        $token = Str::random(40);
        Cache::put('app:'.hash('sha256', $token), ['user' => $user->getAuthIdentifier(), 'product' => $product->id, 'hwid' => $data['hwid'],
            'generation' => self::generation($user->getAuthIdentifier(), $product->id)], 240);

        return $this->reply($request, ['token' => $token, 'until' => $grant->access->validUntil?->getTimestamp()]);
    }

    public function heartbeat(Request $request, Product $product, LicensedDevices $devices): Response
    {
        $key = 'app:'.hash('sha256', (string) $request->bearerToken());
        $session = Cache::get($key);
        if (! is_array($session) || $session['product'] !== $product->id || $session['generation'] !== self::generation($session['user'], $product->id)) {
            return $this->reply($request, ['error' => 'session_ended'], 401);
        }
        try {
            // verify() rechecks the account, license, bans and PC lock on every heartbeat.
            $grant = $devices->verify(User::findOrFail($session['user']), $product, $session['hwid']);
        } catch (LicensingException $exception) {
            return $this->reply($request, ['error' => $exception->error], $exception->status);
        }
        Cache::put($key, $session, 240);

        return $this->reply($request, ['until' => $grant->access->validUntil?->getTimestamp()]);
    }

    public function redeem(Request $request, Product $product, ClaimLicense $claims): Response
    {
        $session = Cache::get('app:'.hash('sha256', (string) $request->bearerToken()));
        if (! is_array($session) || $session['product'] !== $product->id) {
            return $this->reply($request, ['error' => 'session_ended'], 401);
        }
        try {
            $license = $claims->claim(User::findOrFail($session['user']), (string) $request->input('key'));
        } catch (LicensingException $exception) {
            return $this->reply($request, ['error' => $exception->error], $exception->status);
        }

        return $this->reply($request, ['until' => $license->expires_at?->getTimestamp()]);
    }

    /** Listener: a reset, move, suspension or cancellation ends every session for that license. */
    public static function revoke(AccessRevoked $event): void
    {
        Cache::forever('app:generation:'.$event->license->user_id.':'.$event->license->product_id,
            self::generation($event->license->user_id, $event->license->product_id) + 1);
    }

    private static function generation(mixed $user, int $product): int
    {
        return (int) Cache::get('app:generation:'.$user.':'.$product, 0);
    }

    /** @param array<string, mixed> $body */
    private function reply(Request $request, array $body, int $status = 200): Response
    {
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $proof = hash_hmac('sha256', "MYAPP\n".$request->header('X-Nonce')."\n".$status."\n".$json, self::SECRET);

        return response($json, $status, ['Content-Type' => 'application/json', 'X-Proof' => $proof]);
    }
}
