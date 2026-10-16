<?php

namespace LicenseKit\Tests\Core\Fixtures;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LicenseKit\Core\Actions\LicensedDevices;
use LicenseKit\Core\Contracts\DeviceMatcher;
use LicenseKit\Core\Data\AccessAuthorization;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Product;

class HostDesktopController
{
    public function login(Request $request, Product $product, LicensedDevices $devices): JsonResponse
    {
        $data = $request->validate(['login' => 'required|string', 'password' => 'required|string', 'hwid' => 'required|string']);
        $authenticated = app(HostAuthenticateUser::class)->authenticate($data['login'], $data['password']) ?? throw new LicensingException('invalid_credentials', 401);
        $fingerprint = app(ClientSecurity::class)->fingerprint($authenticated);
        $token = DB::transaction(function () use ($devices, $product, $data, $authenticated, $fingerprint): string {
            $user = User::whereKey($authenticated->getKey())->lockForUpdate()->first();
            if ($user === null || ! hash_equals($fingerprint, app(ClientSecurity::class)->fingerprint($user))) {
                throw new LicensingException('invalid_credentials', 401);
            }
            $context = $devices->bind($user, $product, $data['hwid']);
            $token = Str::random(48);
            DB::table('host_desktop_sessions')->insert(['user_id' => $user->getKey(), 'product_id' => $context->access->product->id,
                'token_hash' => hash('sha256', $token), 'fingerprint' => $fingerprint, 'hwid' => $context->device, 'expires_at' => $this->expiry($context->access)]);

            return $token;
        });

        return response()->json(['host_token' => $token]);
    }

    public function heartbeat(Request $request, Product $product, LicensedDevices $devices, ClientSecurity $security, DeviceMatcher $matcher): JsonResponse
    {
        $data = $request->validate(['hwid' => 'required|string']);
        DB::transaction(function () use ($request, $product, $data, $devices, $security, $matcher): void {
            $session = DB::table('host_desktop_sessions')->where('token_hash', hash('sha256', $request->bearerToken() ?? ''))->where('expires_at', '>', now())->first();
            if ($session === null || $session->product_id !== $product->id) {
                throw new LicensingException('invalid_token', 401);
            }
            $user = User::whereKey($session->user_id)->lockForUpdate()->first() ?? throw new LicensingException('invalid_token', 401);
            $session = DB::table('host_desktop_sessions')->where('id', $session->id)->where('expires_at', '>', now())->lockForUpdate()->first() ?? throw new LicensingException('invalid_token', 401);
            if (! hash_equals($session->fingerprint, $security->fingerprint($user))) {
                throw new LicensingException('invalid_token', 401);
            }
            $context = $devices->verify($user, $product, $data['hwid']);
            if (! $matcher->matches($context->access->product, $session->hwid, $context->device)) {
                throw new LicensingException('hwid_mismatch');
            }
            DB::table('host_desktop_sessions')->where('id', $session->id)->update(['expires_at' => $this->expiry($context->access)]);
        });

        return response()->json(['host_valid' => true]);
    }

    private function expiry(AccessAuthorization $access): CarbonImmutable
    {
        $expiry = CarbonImmutable::now()->addMinutes(5);

        return $access->source === 'host_free' && $access->validUntil !== null && $access->validUntil->lessThan($expiry)
            ? $access->validUntil->setTimezone($expiry->getTimezone()) : $expiry;
    }
}
