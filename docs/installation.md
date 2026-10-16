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

Give each model a policy, or allow admins through the `licensekit.manage` gate where a model has no policy. A policy that denies an action wins over that gate.

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

## Optional Filament Shield

Shield is optional. Install [Filament Shield](https://github.com/bezhanSalleh/filament-shield), add Spatie `HasRoles` on your user model, and register `FilamentShieldPlugin::make()` next to `LicenseKitPlugin::make()`. The Shield guard must match that panel and your LicenseKit user provider. LicenseKit does not create roles.

In `config/filament-shield.php`, merge this into `resources.manage`:

```php
\LicenseKit\Filament\Resources\Products\ProductResource::class => ['viewAny', 'view', 'create', 'update', 'resetHwid', 'inspectDevice', 'addTime'],
\LicenseKit\Filament\Resources\LicensePlans\LicensePlanResource::class => ['viewAny', 'view', 'create', 'update', 'createKeys'],
\LicenseKit\Filament\Resources\LicenseKeys\LicenseKeyResource::class => ['viewAny', 'view', 'claim', 'revoke', 'updateNote'],
\LicenseKit\Filament\Resources\Batches\BatchResource::class => ['viewAny', 'view', 'exportUnclaimed', 'revokeUnclaimed'],
\LicenseKit\Filament\Resources\Entitlements\EntitlementResource::class => ['viewAny', 'view', 'create', 'revoke', 'restoreAccess', 'block', 'unblock', 'extend', 'pause', 'resume'],
\LicenseKit\Filament\Resources\ApiClients\ApiClientResource::class => ['viewAny', 'view', 'create', 'update', 'rotateToken', 'revokeTokens'],
\LicenseKit\Filament\Resources\AuditEvents\AuditEventResource::class => ['viewAny', 'view'],
\LicenseKit\Filament\Resources\DeviceResetHistory\DeviceResetHistoryResource::class => ['viewAny', 'view'],
```

Keep class-level `viewAny` and `create` in `policies.single_parameter_methods`. The other abilities need a record. `policies.merge=false` replaces the list for every resource in that config, so include your own resources too.

```sh
php artisan shield:generate --all --panel=admin --no-interaction
```

Replace `admin` with your panel id. Register the generated policies in `boot()` before requests. The files do nothing until you register them. Skip models you turned off.

```php
use Illuminate\Support\Facades\Gate;

foreach ([
    \LicenseKit\Core\Models\Product::class => \App\Policies\ProductPolicy::class,
    \LicenseKit\Core\Models\LicensePlan::class => \App\Policies\LicensePlanPolicy::class,
    \LicenseKit\Core\Models\LicenseKey::class => \App\Policies\LicenseKeyPolicy::class,
    \LicenseKit\Core\Models\Batch::class => \App\Policies\BatchPolicy::class,
    \LicenseKit\Core\Models\Entitlement::class => \App\Policies\EntitlementPolicy::class,
    \LicenseKit\Core\Models\ApiClient::class => \App\Policies\ApiClientPolicy::class,
    \LicenseKit\Core\Models\AuditEvent::class => \App\Policies\AuditEventPolicy::class,
    \LicenseKit\Core\Models\DeviceResetHistory::class => \App\Policies\DeviceResetHistoryPolicy::class,
] as $model => $policy) {
    Gate::policy($model, $policy);
}
```

For a helper role, grant Entitlement `viewAny` / `view` / `block` / `unblock` (suspend and unsuspend) and Product `resetHwid` / `inspectDevice`. Entitlement `create` shows **Suspend a customer**. Add reset-history `viewAny` / `view` only if they should see past resets. Leave out key creation, plans, API clients, and product-wide Add time.

Grant Entitlement `extend`, `pause`, and `resume` separately when staff should manage individual license time. These actions require a reason. See [individual license support](client.md#individual-license-support) for behavior.

A missing policy method denies the action. `licensekit.manage` cannot override that. Clear caches after you change this.
