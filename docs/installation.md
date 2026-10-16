# Installation

PHP 8.3+, Laravel 12 or 13, Filament 4.13.3+ or 5.8.3+, and Sanctum 4.x. Sanctum requires 4.1+ on Laravel 12 or 4.3.1+ on Laravel 13. Your app already has users and login. LicenseKit does not.

Install the pre-release from [Packagist](https://packagist.org/packages/licensekit/licensekit):

```sh
composer require licensekit/licensekit:0.1.0-alpha.2
php artisan licensekit:install --no-interaction
```

When updating from alpha.1, preserve any data you need and rebuild disposable development databases. Alpha.2 changes the initial schema; ordinary `migrate` does not update an already-applied initial migration. No incremental alpha upgrade migration is provided.

Add the plugin to your panel. The panel cannot be a Filament tenant panel, and it has to use your user model.

```php
use LicenseKit\LicenseKitPlugin;

return $panel->plugin(LicenseKitPlugin::make());
```

Publish Sanctum's migrations only if your app does not already have them. Then:

```sh
php artisan migrate
php artisan licensekit:check
```

`licensekit:check` lists bad settings and missing tables. If setup is invalid, the licensing admin stays hidden and requests return 503.

The installer writes `config/licensekit.php` only when that file is missing. It does not change your login, create an admin, or run migrations. Migrations add `licensekit_*` tables and leave your users table alone.

Every panel user is a LicenseKit admin until you define the [staff roles](#staff-roles).

## Settings to choose first

Email verification is required. Set `licensekit.user.require_verified` to `false` only when unverified users should get licenses.

`licensekit.user.display_column` defaults to `email`. Set it to `username` to show usernames in the admin. It must be a real database column. Clear the config cache after you change it.

Choose these before the first device is saved. There is no conversion later.

- `licensekit.device_binding.storage` is `plain` (default) or `encrypted`. Encrypted uses the Laravel app key.
- `licensekit.device_binding.scope` is `per_product` (default) or `global` (one PC for every product on the account).

Buyers can reset their own PC. The wait is `licensekit.device_binding.customer_reset.cooldown_days`, default 7. `0` means no wait. Set `customer_reset.enabled` to `false` to leave resets to an admin.

To ban someone from a product, **Suspend** their license, or use **Suspend a customer** on the Licenses page if they have not bought it yet. A suspension can end on a date you choose.

One account gets one PC per product. A second PC needs another account, or a reset.

## Downtime and maintenance

Turn on **Maintenance mode** on a product to stop the software for everyone, for example during a game update. Licenses keep their time.

Afterwards, use **Add time** on the product to give every running license the lost hours or days back. Paused licenses keep the time for later. You can also include licenses that expired after a chosen moment. It runs immediately and needs no queue worker.

## Staff roles

LicenseKit has two roles, defined as Laravel gates:

| Role | Can do |
| --- | --- |
| `licensekit.admin` | Everything. |
| `licensekit.support` | Browse licenses, keys and HWID reset history; suspend and unsuspend; add time to, pause and resume a license; reset and inspect a device; suspend a customer. |

By default every user who can open your panel is an admin. To restrict staff, define the gates in your `AppServiceProvider::boot()`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('licensekit.admin', fn ($user) => $user->is_owner);
Gate::define('licensekit.support', fn ($user) => $user->is_support);
```

With Filament Shield or spatie/laravel-permission, check a permission inside the gate, for example `Gate::define('licensekit.support', fn ($user) => $user->hasPermissionTo('license support'))`. If you register your own model policies for LicenseKit models, Filament still applies them after the role check.

LicenseKit's PHP services do not check permissions themselves. Your panel, API middleware or own code decides who may call them; the actor you pass is recorded in the activity log.

