# Changelog

## 0.1.2 - 2026-10-29

- Added the optional `licensekit.product` gate to limit staff to some products. Limited staff see only those products' licenses, keys, batches, products and dashboard numbers, pick only those products in forms, and cannot open screens that span every product (store connections, banned PCs, activity log, setup pages, creating products or banning PCs). Without the gate, nothing changes.

## 0.1.1 - 2026-10-28

- Added the `stack_keys` option. Set it to false to refuse timed keys while a license still has time left.
- The Licenses table hides its PC column when no product locks licenses to a PC, and leaves the cell empty for products without a PC lock.

## 0.1.0 - 2026-10-17

First release outside alpha. Until 1.0, minor versions (0.2, 0.3) may include breaking changes; patch versions will not. Every breaking change is listed here.

**Schema:** unchanged since 0.1.0-alpha.3, so databases built on alpha.3 keep working. Databases from earlier alphas must be rebuilt. From this release on, schema changes ship as new migrations.

- **Breaking:** default app API paths move to `api/licensekit/v1/{product}/…`; within v1, responses only gain fields and codes. Store API paths are unchanged.
- Documented the stable PHP API for custom app protocols, with a tested example protocol that uses its own tokens and HMAC signing.
- App guide now starts with building your own protocol; the default API is labelled as an example in docs and on Connect your app. User-facing text says "app" instead of "loader".
- Added "Why can't they sign in?" on the license page: what the app would say now, recent refusals, PC changes and a reply to paste to the customer. App sign-in refusals, including wrong passwords, are logged once per 10 minutes for default and custom protocols, with a "Sign-in problems only" activity filter. Check a customer and the new action share `SignInCheck`.
- Docs: store webhook recipe for Stripe, Shopify and WooCommerce, a `KeyRedeemed` listener example, a README aimed at desktop software sellers, and decisions D11-D13.

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
