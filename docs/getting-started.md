# Getting started

Install into a Laravel app with user accounts and a non-tenant Filament panel:

```sh
composer require licensekit/licensekit
php artisan licensekit:install
php artisan migrate
php artisan licensekit:check
```

Register `\LicenseKit\LicenseKitPlugin::make()` in the panel's plugin list. Missing setup appears on a dedicated panel page.

Installer publishes configuration and creates a private Ed25519 key at `storage/app/private/licensekit-signing.key`. Existing files are preserved. Back up this key with deployment secrets; all app instances need the same key. Replacing it requires shipping a new public key in your app.

## First product and keys

The sidebar has three groups: **Licensing** (Dashboard, Licenses, Keys, Key batches, Products), **Protection** (Banned PCs, Activity log) and **Setup** (Connect your app, Store connections, Team access). The Dashboard shows a checklist until your first app login.

1. Open **Products → Create product**. Give it a name and at least one plan, such as Monthly (30 days) or Lifetime.
2. Leave **Lock each license to one PC** on to stop key sharing, and choose how often customers may move to a new PC. Pick "Never" if only your team should move them.
3. Open **Keys → Generate keys**. The wizard asks for the product and plan, how many keys and an optional label, then shows a summary. Copy the keys or download them as a `.txt` file.
4. To give someone access without a key, use **Licenses → Give license**.
5. Open **Setup → Connect your app**. Paste the public key into your app and start from one of the example clients.

Your app should send a signed heartbeat every 60 seconds and stop when access ends or a check fails. A session expires after 15 minutes without a valid heartbeat.

## Customer website

Add to an authenticated Blade page:

```blade
<livewire:licensekit.my-licenses />
```

Website reset clears device lock and signs out old PC. Next app login binds new PC. App move replaces lock immediately. Both share product cooldown; staff override it.

Publish views for restyling:

```sh
php artisan vendor:publish --tag=licensekit-views
```

Include package views in Tailwind source scan, for example from `resources/css/app.css`:

```css
@source '../../vendor/licensekit/licensekit/resources/views/**/*.blade.php';
```

If host user model implements Laravel's `MustVerifyEmail`, verification is required for redemption and app login. Otherwise verification is not required. Registration and verification emails remain host responsibilities.

## Staff, time and access

Every panel user is an Admin until your app defines the `licensekit.admin` and `licensekit.support` gates. Support can look up licenses, add time, pause and resume, suspend and unsuspend, and reset a customer's PC. Admin can also manage products, generate keys, connect stores and ban PCs. **Setup → Team access** lists this for your team. Your normal model policies still apply on top.

To limit someone to some products, define `licensekit.product`. They then see only those products' licenses, keys, batches and dashboard numbers, and only pick those products when generating keys or giving licenses. They don't see screens that span every product: store connections, banned PCs, the activity log, setup pages and creating products.

```php
Gate::define('licensekit.product', fn (User $user, Product $product): bool => ! $user->hasRole('partner') || $product->slug === 'my-app');
```

On **Licenses**, the tabs (Active, Expiring soon, Paused, Suspended, Expired, Cancelled) sort customers by status, and the sidebar badge counts licenses that run out within 7 days. **Add time** is the main button on every license; everything else is in its **Manage** menu. Each change shows a confirmation and is written to the activity log.

Times are stored in UTC and shown in your app's timezone. Notes are optional.

Maintenance mode stops app access while license clocks keep running. Afterwards, use **Add time** on the product to make up for the downtime. The preview counts paused licenses and, if you choose, licenses that expired recently.

Free window includes start and excludes end. Accounts and device rules still apply. Paid clocks continue; paused customers keep saved time. Suspended and cancelled licenses remain denied.

Pause saves remaining seconds and ends sessions. Redemptions and credits add to saved time. Pause until resumes on next app login/heartbeat, starting saved time from that moment. Lifetime redemption clears pause immediately.
