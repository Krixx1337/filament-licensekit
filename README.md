![LicenseKit](art/licensekit-banner.png)

# LicenseKit

Self-hosted licensing for Laravel and Filament. Customers sign in with their website account, redeem keys, and use their license on one PC.

Includes signed loader API, customer website component, device bans and seller dashboard. Your app owns users, registration and payments.

Requires PHP 8.3+ with sodium, Laravel 12/13, Filament 4.13.3+ or 5.8.3+.

Pre-release. This simplification changes PHP APIs, configuration and initial schema. Use a fresh development database; earlier alpha installs have no upgrade migration.

## Quick start

```sh
composer require licensekit/licensekit
php artisan licensekit:install
php artisan migrate
php artisan licensekit:check
```

Register in your existing non-tenant Filament panel:

```php
$panel->plugin(\LicenseKit\LicenseKitPlugin::make());
```

Open the Dashboard and follow its three-step checklist: create a product with a plan, generate keys, then open **Setup → Connect your app** for the addresses, the public key and example code.

Add to an authenticated Blade page:

```blade
<livewire:licensekit.my-licenses />
```

Every panel user is an admin by default. Define `licensekit.admin` and `licensekit.support` gates before giving staff access. Native model policies still apply.

Sellers can generate and cancel unused keys, give licenses, add time, pause subscriptions, reset devices, ban PCs, schedule free access and connect stores with hashed tokens and safe issuance retries.

## Guides

- [Getting started](docs/getting-started.md)
- [Loader and customer API](docs/client-api.md)
- [Selling keys](docs/selling-keys.md)
- [PHP services and customization](docs/extending.md)
- [Design decisions](docs/decisions.md)

Maintainers: `composer test`, `composer analyse`, `php vendor/bin/pint --test`. CI also checks lowest dependencies, distribution contents and MySQL races. See [release checks](docs/releasing.md).

[MIT license](LICENSE). [Security reporting](SECURITY.md).
