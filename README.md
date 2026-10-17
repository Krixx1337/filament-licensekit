![LicenseKit self-hosted software licensing](art/licensekit-banner.png)

# 🛡️ LicenseKit

> **Your licenses. Your server. Your rules.**

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Packagist](https://img.shields.io/packagist/v/licensekit/licensekit?include_prereleases)](https://packagist.org/packages/licensekit/licensekit)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4.svg)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-12%20%7C%2013-FF2D20.svg)](https://laravel.com)
[![Filament](https://img.shields.io/badge/Filament-4%20%7C%205-FDAE4B.svg)](https://filamentphp.com)

Self-hosted licensing for **game tools, mods and desktop apps**, built right into your Laravel + Filament site.

### ▶️ [Watch the 28-second demo](https://www.youtube.com/watch?v=qwX112s_7ak)

Stop paying monthly fees to a service that can change the rules, go down on a busy weekend, or break the app you already shipped. Run licensing on your own server, with your own data, and no middleman.

I've run licensing for private desktop software since 2019. This is the package I wish I had when I started.

> **Pre-release.** APIs and configuration may change before 1.0. See the [changelog](CHANGELOG.md).

## 🚀 Why sellers switch

* **💳 Sell anywhere, all at once.** Your own site, Stripe, Shopify, WooCommerce, SellAuth, SellApp, Telegram resellers. Payments never touch LicenseKit, so if a payment processor shuts down one store, your licenses keep working.
* **🔒 HWID lock that customers don't hate.** One PC per license. Customers move to a new PC themselves, limited by your cooldown. Staff can override anytime.
* **🚫 PC bans that catch alt accounts.** Ban the hardware, not just the account. LicenseKit warns you when that PC is used by other accounts too.
* **🤝 Built for resellers.** Generate key batches, export a `.txt` in one click, and cancel unsold keys after a payment dispute. Customers who already redeemed keep their time. Resellers get their own page and a bot API. Suspend one account and their bots stop too.
* **🕵️ Support in one click.** "Why can't they sign in?" shows exactly what the app would say, the recent refusals, and a reply ready to paste into Discord.
* **⏸️ Vacation mode, free weekends, downtime credit.** Freeze a subscription and resume it later. Schedule a free weekend. Server was down? Add 6 hours to every active license of that product in one action.
* **🧩 Your protocol, our core.** LicenseKit runs every check on the server: account, license, PC lock, bans, expiry. Your app talks to it any way you like, so there is no one-size-fits-all bypass tool. Starting out? Use the signed example API with ready-made Python, C# and C++ clients.
* **📊 A dashboard you'll actually open.** Live stats, redemption charts, expiring licenses and suspicious PC changes, all in native Filament.

## 📊 Quick comparison

| | KeyAuth | Keygen | **LicenseKit** |
| :--- | :---: | :---: | :---: |
| **Runs on your server** | ❌ Hosted only | ✅ Self-hosted edition | **✅** |
| **Uses your site's accounts** | ❌ | ❌ | **✅** |
| **Your own app protocol** | ❌ Shared SDK protocol | ❌ Their API | **✅ Or use the example** |
| **Admin panel in your Filament** | ❌ | ❌ | **✅** |
| **Reseller tools** | ✅ | — | **✅ Batches, bot API, reseller page** |
| **Price** | Monthly plans | Paid cloud, free self-hosted | **Free, MIT** |

## 🛠️ From sale to running app

1. **A sale happens.** Your store's webhook calls `Keys::issue()`. Webhook retries return the same key. [Store recipes](docs/selling-keys.md).
2. **The customer redeems.** On your website with `<livewire:licensekit.my-licenses />`, or right inside your app.
3. **Your app signs in.** The PC locks, and every heartbeat rechecks the license, bans and PC on the server. [Connect your app](docs/client-api.md).

```php
public function login(Request $request, Product $product, LicensedDevices $devices)
{
    // Check the password your way, then let LicenseKit decide.
    $grant = $devices->bind($user, $product, $request->input('hwid'));

    return ['token' => $yourSessionToken, 'until' => $grant->access->validUntil];
}
```

## 📦 Quick start

```sh
composer require licensekit/licensekit
php artisan licensekit:install
php artisan migrate
php artisan licensekit:check
```

Add it to your Filament panel:

```php
$panel->plugin(\LicenseKit\LicenseKitPlugin::make());
```

Open the dashboard and follow the checklist: create a product, generate keys, then open **Setup → Connect your app** for the addresses, public key and example code.

Every panel user is an admin by default. Define the `licensekit.admin` and `licensekit.support` gates before giving staff access.

## ✅ Compatibility

| | |
| :--- | :--- |
| **PHP** | 8.3+ with sodium |
| **Laravel** | 12, 13 |
| **Filament** | 4.13.3+ or 5.8.3+ |

## 📚 Guides

- [Getting started](docs/getting-started.md)
- [App and customer API](docs/client-api.md)
- [Selling keys and resellers](docs/selling-keys.md)
- [PHP services and customization](docs/extending.md)
- [Design decisions](docs/decisions.md)

Maintainers: `composer test`, `composer analyse`, `php vendor/bin/pint --test`. See [release checks](docs/releasing.md).

[MIT license](LICENSE) · [Security reporting](SECURITY.md)
