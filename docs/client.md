# Connect your app

LicenseKit does not ship a desktop login URL. Your Laravel app authenticates the person, then asks LicenseKit if that account may run on that PC. There is no customer portal in the package. Claim keys from the admin, or add your own page that calls the PHP below.

## Login

Authenticate first. Then bind the PC. `$hwid` is an array, not a bare string. The default shape is `['value' => $fingerprint]`.

```php
use LicenseKit\Core\Actions\LicensedDevices;

$auth = app(LicensedDevices::class)->bind($user, $product, $hwid);
```

`bind()` saves the PC the first time. Later logins on the same PC pass. A different PC is rejected with `hwid_mismatch`. `valid_until` is `$auth->access->validUntil`, and it is null for a lifetime license.

Heartbeats call `verify()`. That checks the saved PC and does not move the lock.

```php
$auth = app(LicensedDevices::class)->verify($user, $product, $hwid);
```

Your app creates the session token. LicenseKit does not.

## Optional device binding

Products require device binding by default. Turn off "Require device binding (HWID)" in the product form, or set `$product->requires_hwid = false` and save, to allow that product on different PCs. Management product responses include the boolean `requires_hwid` setting.

To exempt selected users while keeping protection for other customers, define `bypassHwid` in your host service provider:

```php
use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Models\Product;

Gate::define('bypassHwid', fn ($user, Product $product): bool => (bool) $user->is_admin);
```

Alternatively, add this method to your registered Product policy:

```php
public function bypassHwid($user, Product $product): bool
{
    return (bool) $user->is_admin;
}
```

Use your host application's role or permission check in place of `is_admin`. With Shield/Spatie permissions, you can check `$user->hasPermissionTo('bypass_hwid')`. Create and assign that permission in the host application; LicenseKit does not generate a Shield checkbox. `licensekit.manage` alone does not grant immunity. Laravel Gate hooks, including host super-admin hooks, still apply.

Both exemptions affect direct `DeviceBindings::bind()`/`verify()` and `LicensedDevices::bind()`/`verify()`/`upgrade()`. These calls still require a valid HWID array and run your handler's `assertAllowed()` with the saved baseline when present. Account checks, license suspensions, product restrictions, maintenance mode, and license requirements still apply. Staff immunity grants no entitlement.

Exempt calls accept different PCs without creating or changing a device binding. `verify()` succeeds without a saved PC, and `upgrade()` returns the normalized presented HWID without changing a baseline or emitting an upgrade audit/event. Existing bindings and reset cooldowns remain stored. Customer and admin reset tools keep their existing behavior.

Re-enabling protection or removing immunity restores the saved device lock. With no saved binding, call `bind()` before `verify()` as usual. In global scope, disabling binding for one product leaves the shared lock intact for protected products.

## Device errors

| Error | Meaning |
| --- | --- |
| `invalid_hwid` | The payload is not a JSON object the handler accepts. HTTP 422. |
| `hwid_mismatch` | This is not the saved PC. HTTP 403. |
| `device_not_bound` | `verify()` ran before any PC was saved. HTTP 409. |

## Hardware match

The default handler stores one string in `value` and compares it exactly. The string can be up to 1024 bytes.

For a GPU or disk swap to stay allowed, replace the handler. Set `hwid_handler` in `config/licensekit.php` to your class. It implements `LicenseKit\Core\Contracts\HwidHandler`.

- `normalize()` checks the payload and returns the array you store.
- `matches()` returns true when the presented PC is still the saved one. Exact or fuzzy both belong here. The package has no Windows score and no threshold.
- `canUpgrade()` returns true only when you mean to replace the saved PC.
- `assertAllowed()` rejects a device you want banned. The saved PC is null on the first bind.

A fuzzy accept does not rewrite the saved PC. Call `upgrade()` only when you intend a new payload, such as a new hardware schema. Do not call it on every fuzzy match. That would let the baseline drift.

```php
app(LicensedDevices::class)->upgrade($user, $product, $newHwid);
```

Choose storage and scope before the first bind. See [installation](installation.md#settings-to-choose-first).

## Claim a key

The buyer is already logged in on your site. Pass their user and the serial.

```php
use LicenseKit\Core\Actions\ClaimLicense;

$entitlement = app(ClaimLicense::class)->claim($user, $serial);
```

One paid license exists per user and product. A second claim on a timed plan extends it. A lifetime plan rejects another claim with `already_lifetime`. Unknown or used keys return `invalid_key` or `key_already_claimed`.

Staff can claim for someone from the admin. That uses `ClaimLicense::claimFor($actor, $user, $serial)`.

## Individual license support

Staff can extend, pause, and resume one user's license from the Licenses table, or through PHP:

```php
use LicenseKit\Core\Actions\ManageEntitlements;

$licenses = app(ManageEntitlements::class);
$licenses->extend($entitlement, 3 * 86400, $admin, 'Support credit');
$licenses->pause($entitlement, $admin, 'Customer requested a vacation pause');
$licenses->resume($entitlement, $admin, 'Customer returned');
```

Each call checks its own policy ability, `extend`, `pause`, or `resume`, and requires a reason of 1–2000 characters after trimming. Extensions accept 1–315360000 seconds. They add to a future expiry or start from now if expired. A paused license banks the added seconds.

Pause saves remaining seconds and clears expiry. Resume starts that balance from now. Lifetime, cancelled, suspended, and deleted-user licenses cannot use these actions. Pausing an expired license or repeating a pause/resume returns HTTP 409 with `cannot_pause_entitlement` or `cannot_resume_entitlement`.

While paused, paid access, host-provided free access, and key claims fail with `subscription_paused`, HTTP 403. Keys stay unclaimed, including lifetime keys. Resume first before claiming. Customer summaries report `paused`, unless cancelled or suspended, and expose `paused_at` and `remaining_seconds`. A null `expires_at` alone does not mean lifetime.

Pause dispatches `EntitlementAccessInvalidated` inside the transaction. Your host app must listen to that event to invalidate its sessions. Bind and verify also reject paused licenses. Keep listener database writes in the same transaction; defer external side effects until commit.

Product-wide **Add time** adds the time to a paused license's banked balance instead of its end date.

## License status

Each license has one `status`: `active`, `paused`, `suspended` or `revoked`.

```php
use LicenseKit\Core\Actions\ManageEntitlements;

$licenses = app(ManageEntitlements::class);
$licenses->suspend($license, $admin, 'Chargeback', until: now()->addWeek()); // until is optional
$licenses->unsuspend($license, $admin);
$licenses->revoke($license, $admin, 'Refund');   // "Cancel license" in the admin
$licenses->restore($license, $admin);
$licenses->suspendCustomer($user, $product, $admin, 'Cheating'); // works before a purchase
```

- **Suspended** stops all access to that product, including free access, and rejects key redemption with `license_suspended`. The clock keeps running. A suspension with `until` ends by itself.
- **Revoked** (cancelled) stops paid access only. Another key cannot renew it. Host-approved free access still works.
- Unsuspending or restoring returns the license to `paused` if it was paused, otherwise `active`.
- Suspending or revoking dispatches `EntitlementAccessInvalidated` and `ProductAccessInvalidated` inside the transaction.

Products have one `status` too: `active`, `maintenance` (the software stops working for everyone, `service_unavailable`) or `discontinued` (no new keys, existing licenses keep working).

## Reset

The owner can clear their PC. The default wait is 7 days.

```php
use LicenseKit\Core\Actions\DeviceBindings;

app(DeviceBindings::class)->reset($user, $product, $user);
```

`customer_reset_disabled` means you turned self-service off. `reset_cooldown` means the wait has not finished. An admin override ignores the wait and needs a reason:

```php
app(DeviceBindings::class)->adminReset($user, $product, $admin, 'Customer replaced their PC');
```

Successful resets stay on the HWID reset history page in the admin, including the device that was cleared.


## Claim events and provenance

`EntitlementGranted` remains synchronous inside the claim transaction. Its listeners can change licensing state, and a listener exception rolls back the claim. The optional `claim` property contains immutable `ClaimProvenance`: key/batch IDs, issuer/reference, stable `claim:{keyId}` operation identity, entitlement/user/product IDs, UTC claim time, and before/after expiry/lifetime values. No serial is included. At this hook, the key's entitlement link has not yet been written.

`LicenseClaimed` carries completed `ClaimProvenance` in its `claim` property. It dispatches after the outermost transaction commits and after key linkage is saved. Its after-state includes synchronous grant-listener changes. Rollback discards it. Data consists of scalar values and immutable timestamps rather than mutable Eloquent models.

Use after-commit listeners for delivery notifications and external calls. A listener failure after commit cannot undo the claim; delivery guarantees need a host outbox or reconciliation. Use synchronous listeners only for database work that must participate in the claim transaction.

Host-provided grants remain host decisions. `AccessPolicy::assertAllowed()` checks universal restrictions but does not prove paid entitlement. Approve a host grant before using `DeviceBindings::bind()`, ideally within the same transaction. Keep account authentication, session cleanup, and desktop response/error protocols in the host.
