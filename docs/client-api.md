# App and customer API

Your app's network protocol is yours. LicenseKit runs the licensing checks; you pick routes, fields, tokens and signing. A protocol only your app speaks also means no universal bypass tool works against it.

The [default API](#default-api-example) is a lazy example that works out of the box. Use it to start, then prefer your own.

## Build your own (recommended)

Call the [stable PHP API](extending.md#stable-php-api) from your own controllers. Every check (account, license, PC lock, bans, expiry) runs inside those calls, so your protocol cannot skip one.

1. Sign in: check the password with your host's auth provider.
2. `LicensedDevices::bind($user, $product, $device)`: checks access and bans, locks the first PC, rejects others.
3. Issue your own session token. Store it however you like.
4. Every heartbeat: `LicensedDevices::verify(...)` again. It rechecks account, license, bans and PC.
5. Listen for `AccessRevoked` and end that license's sessions (reset, move, suspension, cancellation).
   Refusals are logged for support automatically; wrong passwords are yours to log with `SignInProblems::record`.
6. Optional: `moveDevice` for a new PC (product cooldown applies), `ClaimLicense::claim` to redeem keys.
7. Sign responses your way, and pin the key or secret in your app.

```php
public function login(Request $request, Product $product, LicensedDevices $devices): JsonResponse
{
    $hwid = (string) $request->input('hwid');
    $credentials = ['email' => (string) $request->input('username'), 'password' => (string) $request->input('password')];
    $provider = Auth::guard()->getProvider();
    $user = $provider->retrieveByCredentials($credentials);
    if ($user === null || ! $provider->validateCredentials($user, $credentials)) {
        return response()->json(['error' => 'bad_login'], 401);
    }
    try {
        $grant = $devices->bind($user, $product, $hwid);
    } catch (LicensingException $exception) {
        return response()->json(['error' => $exception->error], $exception->status);
    }
    $token = Str::random(40);
    Cache::put('app:'.hash('sha256', $token), ['user' => $user->getAuthIdentifier(), 'hwid' => $hwid], 240);

    return response()->json(['token' => $token, 'until' => $grant->access->validUntil?->getTimestamp()]);
}
```

Full working example with heartbeat, move, redeem, revocation and HMAC proofs: [controller](../tests/Core/Fixtures/CustomProtocolController.php) and [test](../tests/Core/Feature/CustomProtocolTest.php).

Signing stops fake servers, not patched binaries. Keep decisions on the server; your app should only trust signed answers.

Set `client_enabled=false` to turn the default API off.

## Default API (example)

On by default. Change `client_prefix` or set `client_enabled=false`. `{product}` is product slug. Use JSON over HTTPS. Its paths carry `v1`: within v1, responses only gain fields and error codes, so shipped apps keep working. Ignore unknown fields and treat unknown codes as a failure.

| POST endpoint | Body, plus nonce |
|---|---|
| `/api/licensekit/v1/{product}/login` | `login`, `password`, `device` |
| `/api/licensekit/v1/{product}/heartbeat` | `token`, `device` |
| `/api/licensekit/v1/{product}/redeem` | `token`, `key` |
| `/api/licensekit/v1/{product}/move-device` | `login`, `password`, `device` |

Credentials use host Laravel provider. `client_login_column` defaults to `email`; `username` is supported. Registration stays on website. Website 2FA does not apply to app password login.

### First redemption and access

Successful login returns `token`, session `expires_at`, `heartbeat_seconds`, `access`, `code`, `access_valid_until` and nullable `license`. License shape is `{status, expires_at, lifetime}`.

Without paid/free access, login can return `access=false` with `no_license`, `expired` or `paused`. Token permits redemption, not software access. Redeem first key using it, then check returned `access`. Tokens and redemption are product-scoped.

Sessions slide forward 15 minutes on heartbeat. Several sessions on same PC are allowed. Heartbeat rechecks account, product, license and device. Password changes invalidate sessions. Paid/free access has its own deadline; lifetime paid access has none.

Different locked PC returns `device_mismatch`, `can_move`, `move_available_at`. Ask before move-device. Move rechecks credentials/cooldown and ends old sessions. Expired licenses can move but stay restricted until renewed.

### Verify every response

Send fresh random lowercase hex nonce, 32-128 characters, with every request. Responses echo nonce and Unix `server_time`.

`X-LicenseKit-Signature` is Base64 detached Ed25519 signature over exact UTF-8 body bytes. Verify before trusting access. Pin public key from Connect your app; never accept replacement key from response. Check nonce equality and timestamp within 120 seconds of local clock. Reject missing/invalid signatures and stale responses.

Success, validation, authentication errors and rate limits are signed. Missing signing setup returns 503 without access.

Example clients: [Python](../resources/snippets/client.py), [C#](../resources/snippets/Client.cs), [C++](../resources/snippets/client.cpp). Panel fills endpoint and public key. [curl](../resources/snippets/client.sh) is diagnostic only and does not verify signatures. CI runs the Python example against a live default API.

### Errors

Errors return `{error, message}`, nonce and timestamp. Branch on code; show human-readable message.

| Code | Next step |
|---|---|
| `invalid_credentials`, `invalid_token` | Sign in again |
| `no_license`, `expired` | Redeem key |
| `suspended`, `cancelled`, `device_banned` | Contact support |
| `device_mismatch` | Offer move when available |
| `move_cooldown`, `customer_reset_disabled` | Wait or contact support |
| `paused` | Saved time remains paused |
| `email_not_verified` | Verify on website |
| `maintenance` | Try later |
| `invalid_key`, `key_already_used` | Check key or contact seller |

Login limits use IP and login/IP pair. Other calls use session/IP. Hosts can redefine named `licensekit.login`, `licensekit.session`, `licensekit.management` limiters.

To adapt it instead of starting fresh, copy the thin [controller](../src/Core/Http/Controllers/LoaderController.php) into your host and call `LoaderSessions::login`, `heartbeat`, `redeem` (`move: true` for movement). Keep signing and rate limits.

Generate an OpenAPI specification for enabled host routes:

```sh
php artisan licensekit:openapi --output=licensekit-openapi.json
```

## Customer JSON routes

Set `portal_api_enabled=true` for SPA hosts. Defaults under `/account`:

- `GET /licenses`
- `POST /redeem`, body `{key}`
- `POST /licenses/{license}/reset-device`

Host web session, CSRF protection and license ownership checks apply. Prefer supplied Livewire component for Blade sites.
