# Connect your app

LicenseKit does not ship a desktop login URL. Your Laravel app authenticates the person, then asks LicenseKit if that account may run on that PC. There is no customer portal in the package. Claim keys from the admin, or add your own page that calls the PHP below.

## Login

Authenticate first. Then lock the PC. The device is a string your app sends, such as a hardware fingerprint, up to 1024 bytes.

```php
use LicenseKit\Core\Actions\LicensedDevices;

$auth = app(LicensedDevices::class)->bind($user, $product, $device);
```

`bind()` saves the device on the customer's license the first time. Later logins from the same device pass. A different device is rejected with `hwid_mismatch`. `valid_until` is `$auth->access->validUntil`, and it is null for a lifetime license.

Heartbeats call `verify()`. It checks the saved device and never changes it.

```php
$auth = app(LicensedDevices::class)->verify($user, $product, $device);
```

Your app creates the session token. LicenseKit does not.

## Device lock settings

Each product has two settings in the admin:

- **Lock each license to one device** (`requires_hwid`, on by default). Turn it off to allow any PC.
- **Customers can move their license to a new PC** (`device_move_cooldown_days`): any time, once a day, once a week (default), every 2 weeks, once a month, or never (support only).

To exempt selected users, such as your own staff, define `bypassHwid`:

```php
use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Models\Product;

Gate::define('bypassHwid', fn ($user, Product $product): bool => (bool) $user->is_admin);
```

Exempt users can log in from any PC and nothing is saved. They still need a valid license.

## Device errors

| Error | Meaning |
| --- | --- |
| `invalid_hwid` | The device string is empty or longer than 1024 bytes. HTTP 422. |
| `hwid_mismatch` | This is not the saved device. HTTP 403. |
| `device_not_bound` | `verify()` ran before any device was saved. HTTP 409. |

## Hardware changes

The default matcher compares the device string exactly. To let a GPU or disk swap stay allowed, set `device_matcher` in `config/licensekit.php` to your own class implementing `LicenseKit\Core\Contracts\DeviceMatcher`:

```php
public function matches(Product $product, string $bound, string $presented): bool
```

A match never rewrites the saved device, so it cannot drift over time.

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

Each call requires a reason of 1–2000 characters after trimming. In the admin, support staff can use these actions. Extensions accept 1–315360000 seconds. They add to a future expiry or start from now if expired. A paused license banks the added seconds.

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

## Moving to a new PC

Customers can fix their own device lock, limited by the product's waiting period:

```php
use LicenseKit\Core\Actions\LicensedDevices;

$devices = app(LicensedDevices::class);
$devices->moveDevice($user, $product, $newDevice); // from your app on the new PC: one step
$devices->reset($user, $product);                  // from your website: clears it, the next login locks the new PC
$devices->eligibility($license);                   // ['allowed' => ..., 'available_at' => ..., 'reason' => ...]
```

`customer_reset_disabled` means the product is set to support only. `reset_cooldown` (HTTP 429) means the wait has not finished. Staff can always reset from the Licenses page, or with:

```php
$devices->adminReset($user, $product, $admin, 'Customer replaced their PC');
```

Every move and reset is written to the activity log with the old device, so support can spot accounts that keep switching PCs.

## Claim events and provenance

`EntitlementGranted` remains synchronous inside the claim transaction. Its listeners can change licensing state, and a listener exception rolls back the claim. The optional `claim` property contains immutable `ClaimProvenance`: key/batch IDs, issuer/reference, stable `claim:{keyId}` operation identity, entitlement/user/product IDs, UTC claim time, and before/after expiry/lifetime values. No serial is included. At this hook, the key's entitlement link has not yet been written.

`LicenseClaimed` carries completed `ClaimProvenance` in its `claim` property. It dispatches after the outermost transaction commits and after key linkage is saved. Its after-state includes synchronous grant-listener changes. Rollback discards it. Data consists of scalar values and immutable timestamps rather than mutable Eloquent models.

Use after-commit listeners for delivery notifications and external calls. A listener failure after commit cannot undo the claim; delivery guarantees need a host outbox or reconciliation. Use synchronous listeners only for database work that must participate in the claim transaction.

Host-provided grants remain host decisions. `AccessPolicy::assertAllowed()` checks universal restrictions but does not prove paid entitlement. Approve a host grant before calling `LicensedDevices::bind()`, ideally within the same transaction. Keep account authentication, session cleanup, and desktop response/error protocols in the host.
