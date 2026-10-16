# Changelog

## Unreleased

## 0.1.0-alpha.3 - 2026-10-16

Pre-release APIs and configuration may change before the first stable release.

**Schema compatibility:** the initial migration changed. Existing disposable alpha development databases must be rebuilt; ordinary `migrate` does not update an already-applied initial migration. No incremental alpha upgrade migration is provided. Preserve any data you need before rebuilding.

- Rebuilt the admin panel with native Filament components: dashboard stats and charts, needs-attention lists, license and key status tabs, a generate-keys wizard, full product pages, and a license page with grouped management actions. Navigation now groups Licensing, Protection and Setup. The dashboard includes repository and creator credits.
- Added PC history for first locks, moves and resets, with staff attribution and stable PC names. Frequent changes and shared PCs appear as warnings. The activity log can filter PC changes.
- Added reseller website accounts through optional batch attribution and connection ownership. Resellers can see purchase counts and download unused keys through `ResellerBatches` and `<livewire:licensekit.reseller-batches />`. Admin batches, keys and licenses show who sold each key. Suspended owners lose bot access; deleting an owner disables their connections. Email verification does not block bot tokens.
- Connect your app now provides full example copy and download actions, independent C# and C++ tabs, and optional highlighted previews through `phiki/phiki`.
- Fixed expired loader-session cleanup, timed suspension handling, and invalid suspend/cancel restore transitions. Another customer's license returns 404 consistently across Livewire versions. License status stays visible on phones and empty chart slices are hidden.
- **Breaking:** renamed entitlements to licenses and replaced old administration views with native schemas and pages. Custom views and integrations must follow the current API and documentation.
- **Breaking:** each license stores one device per product. `LicensedDevices` handles binding, verification, movement and reset; a one-method `DeviceMatcher` replaces the old HWID handler. Products control the reset cooldown. Removed device-binding and reset-history tables, global device scope and encrypted device storage.
- **Breaking:** admin access uses `licensekit.admin` and `licensekit.support` gates, followed by host model policies. Removed the former per-model ability set and authorization wrapper. Trusted PHP services record actors; callers authorize access.
- **Breaking:** licenses use one status with optional timed suspension, replacing revoke/block flags and separate licensing bans. Products use one status for active, maintenance and discontinued states.
- **Breaking:** replaced the Outages module with synchronous product time credits for running, paused and optionally recently expired licenses. Removed product groups, historical imports, replaceable key normalization and obsolete reseller inventory/connection-retirement APIs. `SerialKey` handles standard 29-character keys; reseller APIs use the current account-based model above.
- Support remains PHP 8.3+, Laravel 12 and 13, and Filament 4.13.3+ or 5.8.3+. Sanctum is no longer required.

## 0.1.0-alpha.2 - 2026-10-15

Pre-release APIs and configuration may change before the first stable release.

**Schema compatibility:** the initial migration changed. Existing disposable alpha development databases must be rebuilt; ordinary `migrate` does not update an already-applied initial migration. No incremental alpha upgrade migration is provided. Preserve any data you need before rebuilding.

- Trusted local application issuance with integration scope, explicit serial disclosure, and stable retry results.
- Replaceable key normalization and initial-schema support for 255-byte canonical keys with exact equality.
- Explicitly enabled historical entitlement, key, device, and reset imports with preflight reports and source fingerprints.
- Immutable claim provenance and after-commit `LicenseClaimed` events; synchronous outage hooks remain intact.
- Core catalog operations accept explicit actors independently of a Filament panel.
- UTC key/device timestamps. The initial schema includes all alpha changes; no incremental upgrade path is maintained for disposable development databases.
- Fixed concurrent local issuance returning incomplete key lists or incorrect replay status when a host transaction has an older MySQL snapshot.
- Fixed historical reset imports shifting timestamps in applications configured with a non-UTC timezone.

## 0.1.0-alpha.1 - 2026-10-15

Initial pre-release of LicenseKit.

- Licensing APIs for products, plans, license keys, entitlements, and device bindings.
- Filament administration with Laravel policy support.
- Optional outage compensation, downloads, free access, and licensing bans.
- Support for PHP 8.3+, Laravel 12 and 13, Filament 4.13.3+ and 5.8.3+, and Sanctum 4.x.

Pre-release APIs and configuration may change before the first stable release.
