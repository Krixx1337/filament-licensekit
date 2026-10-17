![LicenseKit](art/licensekit-banner.png)

# LicenseKit

Self-hosted licensing for desktop software with HWID locks: game tools, mods, cheats and utilities. It runs inside your Laravel and Filament site.

- **Sell anywhere.** Your own site, Stripe, Shopify, WooCommerce or Telegram resellers, all at once. Payments stay outside LicenseKit, so losing a payment processor never touches your licenses.
- **Your protocol, our core.** LicenseKit runs every check: account, license, PC lock, bans and expiry. Your app talks to it however you like; a signed example API gets you started.
- **Account-first.** Buyers register once (two minutes) and redeem keys. You get a trail from key to reseller to player, one-click bans and self-service PC resets.
- **Support built in.** See why a customer can't sign in, and paste the reply.

| | Hosted services such as KeyAuth | LicenseKit |
|---|---|---|
| Runs on | Their servers | Your server |
| Customer data | Their database | Your database |
| App protocol | Same for every seller | Yours (example included) |
| Stability | Their release schedule | Versioned API and stable PHP services |
| Cost | Monthly plan | Free, MIT |

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
- [App and customer API](docs/client-api.md)
- [Selling keys](docs/selling-keys.md)
- [PHP services and customization](docs/extending.md)
- [Design decisions](docs/decisions.md)

Maintainers: `composer test`, `composer analyse`, `php vendor/bin/pint --test`. CI also checks lowest dependencies, distribution contents and MySQL races. See [release checks](docs/releasing.md).

[MIT license](LICENSE). [Security reporting](SECURITY.md).
