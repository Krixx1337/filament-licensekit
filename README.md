![LicenseKit self-hosted software licensing](art/licensekit-banner.png)

# 🛡️ LicenseKit

> **A Filament plugin for self-hosted software licensing and HWID node-locking.**

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4.svg)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-12%20%7C%2013-FF2D20.svg)](https://laravel.com)
[![Filament](https://img.shields.io/badge/Filament-4%20%7C%205-FDAE4B.svg)](https://filamentphp.com)

**LicenseKit** is a Filament plugin and a self-hosted licensing engine for independent developers selling desktop applications, game enhancements, private clients, and automation tools.

Stop paying monthly SaaS fees to services that police your business or crash when other users get DDoS'd. Host your own licensing infrastructure on your own servers with zero middleman risk.

I have run licensing for private desktop software since 2019. This is the package I wish I had when I started.

> **Pre-release.** LicenseKit is available on [Packagist](https://packagist.org/packages/licensekit/licensekit) as `0.1.0-alpha.2`. APIs and configuration may change before the first stable release.

Alpha.2 adds [local application issuance](docs/shops.md#from-the-same-laravel-application) and [claim events](docs/client.md#claim-events-and-provenance). Existing disposable alpha development databases must be rebuilt because the initial schema changed. See the [changelog](CHANGELOG.md) before updating.

Maintainer instructions are in [publishing](docs/releasing.md). Changes are recorded in the [changelog](CHANGELOG.md).

### 🚀 Why LicenseKit?

* **💳 100% Commerce Agnostic (Gateway Immunity)**: Run your own storefront, or sell keys through Shopify, Stripe, WooCommerce, or Telegram resellers. Connect your store through your own webhook or API integration. Use any of them at the same time. If a payment processor shuts down one store, your licensing database is untouched.
* **💻 Resilient HWID Node-Locking**: Strict exact matching out of the box, with a custom handler interface for multi-signal fuzzy matching. Define your own hardware-change rules so legitimate GPU or SSD upgrades can stay allowed.
* **⚡ Downtime Compensation**: Planned game update or unexpected crash? Put the product in maintenance mode, then give every running license the lost time back in one click.
* **🤝 Built for Reseller Distribution**: Create license keys in batches, export clean `.txt` files in 1 click, and revoke unsold batches with one click if a reseller disputes payment.
* **🎨 Beautiful Filament Admin UI**: Turnkey dashboard, [Filament Shield](https://github.com/bezhanSalleh/filament-shield) ready. Shield is optional and not bundled. Let support staff inspect hardware diffs and reset devices without giving them access to create license keys or API tokens.
* **🔒 Host-Owned Security**: LicenseKit owns the entitlement math and hardware locks. You own customer authentication, desktop handshake protocols, and billing.

### 📊 Quick comparison

| Feature | KeyAuth (SaaS) | Keygen.sh | Basic packages | **LicenseKit** |
| :--- | :---: | :---: | :---: | :---: |
| **Self-Hosted / Sovereign** | ❌ | ❌ | ✅ | **✅ (100% Free MIT)** |
| **No Risk of Account Bans** | ❌ (ToS) | ❌ (ToS) | ✅ | **✅ (You own the DB)** |
| **Turnkey Admin Dashboard** | ⚠️ (Outdated) | ⚠️ (Basic) | ❌ | **✅ (Modern Filament)** |
| **HWID Fuzzy Matching** | ❌ (Exact only) | ⚠️ (Custom) | ❌ | **✅ Custom handler interface** |
| **Downtime Time Compensation** | ❌ | ❌ | ❌ | **✅ One-click add time** |
| **Multi-Storefront Support** | ⚠️ (Clunky) | ✅ | ⚠️ | **✅ Native idempotency** |

### 🛠️ Architecture in 30 seconds

LicenseKit plugs into the Laravel app you already have.

1. **Your storefront, or a webhook** from Stripe or Shopify, creates license keys. The same order id returns the same keys. [Create license keys](docs/shops.md).
2. **Customer** claims the key on your site through `ClaimLicense::claim($user, $serial)`.
3. **Desktop client** logs in through your controller. `LicensedDevices::bind()` checks the license and locks the PC.

```php
use LicenseKit\Core\Actions\LicensedDevices;

public function login(Request $request, LicensedDevices $devices)
{
    $user = $request->user();

    $auth = $devices->bind($user, $product, $request->input('hwid'));

    return response()->json([
        'token' => $user->createToken('desktop-session')->plainTextToken,
        'valid_until' => $auth->access->validUntil,
    ]);
}
```

`$hwid` is an array. Heartbeats call `verify()`, which checks the saved PC and does not move the lock. [Connect your app](docs/client.md).

### ✅ Compatibility

| | |
| :--- | :--- |
| **PHP** | 8.3+ |
| **Laravel** | 12, 13 |
| **Filament** | 4.13.3+ or 5.8.3+ |
| **Sanctum** | 4.1+ on Laravel 12, 4.3.1+ on Laravel 13 |
| **[Filament Shield](https://github.com/bezhanSalleh/filament-shield)** | 4.x, optional |
| **spatie/laravel-permission** | Selected by Composer for your PHP and Shield versions, optional |

LicenseKit checks ordinary Laravel policies. Install Shield in your app when you want roles. It is not a LicenseKit dependency. Custom actions such as reset HWID and create keys need the [Shield recipe](docs/installation.md#optional-filament-shield). A policy that says no is final.

### 📦 Installation

PHP 8.3+, Laravel 12 or 13, Filament 4.13.3+ or 5.8.3+, and Sanctum 4.x. Sanctum requires 4.1+ on Laravel 12 or 4.3.1+ on Laravel 13. Accounts and login stay in your app.

Install the alpha from Packagist, then:

```sh
composer require licensekit/licensekit:0.1.0-alpha.2
php artisan licensekit:install --no-interaction
```

```php
use LicenseKit\LicenseKitPlugin;

return $panel->plugin(LicenseKitPlugin::make());
```

```sh
php artisan migrate
php artisan licensekit:check
```

Shield permissions, host user settings, and the rest of setup are in [installation](docs/installation.md). After that, [connect your app](docs/client.md) and [create license keys](docs/shops.md).
