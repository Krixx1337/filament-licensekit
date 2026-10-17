# Loader and customer API

Default loader routes are on. Change `client_prefix` or set `client_enabled=false` to replace them. `{product}` is product slug. Use JSON over HTTPS.

The default API is a working example, not a hardened protocol. Its paths carry `v1`: within v1, responses only gain fields and error codes, so shipped apps keep working. Ignore unknown fields and treat unknown codes as a failure. Prefer [your own protocol](#custom-controllers-and-openapi).

| POST endpoint | Body, plus nonce |
|---|---|
| `/api/licensekit/v1/{product}/login` | `login`, `password`, `device` |
| `/api/licensekit/v1/{product}/heartbeat` | `token`, `device` |
| `/api/licensekit/v1/{product}/redeem` | `token`, `key` |
| `/api/licensekit/v1/{product}/move-device` | `login`, `password`, `device` |

Credentials use host Laravel provider. `client_login_column` defaults to `email`; `username` is supported. Registration stays on website. Website 2FA does not apply to loader password login.

## First redemption and access

Successful login returns `token`, session `expires_at`, `heartbeat_seconds`, `access`, `code`, `access_valid_until` and nullable `license`. License shape is `{status, expires_at, lifetime}`.

Without paid/free access, login can return `access=false` with `no_license`, `expired` or `paused`. Token permits redemption, not software access. Redeem first key using it, then check returned `access`. Tokens and redemption are product-scoped.

Sessions slide forward 15 minutes on heartbeat. Several sessions on same PC are allowed. Heartbeat rechecks account, product, license and device. Password changes invalidate sessions. Paid/free access has its own deadline; lifetime paid access has none.

Different locked PC returns `device_mismatch`, `can_move`, `move_available_at`. Ask before move-device. Move rechecks credentials/cooldown and ends old sessions. Expired licenses can move but stay restricted until renewed.

## Verify every response

Send fresh random lowercase hex nonce, 32-128 characters, with every request. Responses echo nonce and Unix `server_time`.

`X-LicenseKit-Signature` is Base64 detached Ed25519 signature over exact UTF-8 body bytes. Verify before trusting access. Pin public key from Connect your app; never accept replacement key from response. Check nonce equality and timestamp within 120 seconds of local clock. Reject missing/invalid signatures and stale responses.

Success, validation, authentication errors and rate limits are signed. Missing signing setup returns 503 without access. Signatures cannot prevent someone patching their own loader.

Copy verification clients: [Python](../resources/snippets/client.py), [C#](../resources/snippets/Client.cs), [C++](../resources/snippets/client.cpp). Panel fills endpoint and public key. [curl](../resources/snippets/client.sh) is diagnostic only and does not verify signatures.

## Errors

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

## Customer JSON routes

Set `portal_api_enabled=true` for SPA hosts. Defaults under `/account`:

- `GET /licenses`
- `POST /redeem`, body `{key}`
- `POST /licenses/{license}/reset-device`

Host web session, CSRF protection and license ownership checks apply. Prefer supplied Livewire component for Blade sites.

## Custom controllers and OpenAPI

Build on the [stable PHP API](extending.md#stable-php-api); it runs every check. [CustomProtocolTest](../tests/Core/Feature/CustomProtocolTest.php) is a full example with its own routes, tokens and HMAC signing. Or disable `client_enabled`, copy thin [loader controller](../src/Core/Http/Controllers/LoaderController.php) into host and adapt routes. Call `LoaderSessions::login`, `heartbeat`, `redeem`; use `move: true` for movement. Retain signing and rate limits for this protocol. See [PHP services](extending.md).

Generate specification for enabled host routes:

```sh
php artisan licensekit:openapi --output=licensekit-openapi.json
```

Command reads registered routes and request validation rules. Enable store/portal routes before generating to include them.
