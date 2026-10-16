# Getting started

Install into a Laravel app with user accounts and a non-tenant Filament panel:

```sh
composer require licensekit/licensekit
php artisan licensekit:install
php artisan migrate
php artisan licensekit:check
```

Register `\LicenseKit\LicenseKitPlugin::make()` in the panel's plugin list. Missing setup appears on a dedicated panel page.

Installer publishes configuration and creates a private Ed25519 key at `storage/app/private/licensekit-signing.key`. Existing files are preserved. Back up this key with deployment secrets; all app instances need the same key. Replacing it requires shipping a new public key in loaders.

## First product and keys

1. Open Dashboard, then Products. Create a product with an inline plan.
2. Choose duration or Lifetime. Leave device locking on to bind licenses to first PC.
3. Choose customer move cooldown or Never for support-only changes.
4. Open Keys / Generate keys. Pick product, plan and quantity. Label is optional.
5. Copy results or explicitly download `.txt`. Give license on Licenses issues and redeems for a customer in one action.
6. Open Settings / Connect your app. Embed its public key and use a client example.

Loaders should check signed heartbeat every 60 seconds and stop allowing access after access deadline or failed check. Sessions expire after 15 minutes without valid heartbeat.

## Customer website

Add to an authenticated Blade page:

```blade
<livewire:licensekit.my-licenses />
```

Website reset clears device lock and signs out old PC. Next loader login binds new PC. Loader move replaces lock immediately. Both share product cooldown; staff override it.

Publish views for restyling:

```sh
php artisan vendor:publish --tag=licensekit-views
```

Include package views in Tailwind source scan, for example from `resources/css/app.css`:

```css
@source '../../vendor/licensekit/licensekit/resources/views/**/*.blade.php';
```

If host user model implements Laravel's `MustVerifyEmail`, verification is required for redemption and loader login. Otherwise verification is not required. Registration and verification emails remain host responsibilities.

## Staff, time and access

Every panel user is admin unless your host defines `licensekit.admin` and `licensekit.support`. Support can look up licenses, add time, pause/resume, suspend/unsuspend and reset devices. Admin also manages products, issuance, connections and bans. Native model policies can restrict further.

Store UTC; display app/panel local time. Notes are optional.

Maintenance stops loader access while license clocks continue. Add time afterwards to credit downtime; preview includes paused licenses and optionally recently expired licenses.

Free window includes start and excludes end. Accounts and device rules still apply. Paid clocks continue; paused customers keep saved time. Suspended and cancelled licenses remain denied.

Pause saves remaining seconds and ends sessions. Redemptions and credits add to saved time. Pause until resumes on next loader login/heartbeat, starting saved time from that moment. Lifetime redemption clears pause immediately.
