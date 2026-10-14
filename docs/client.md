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
