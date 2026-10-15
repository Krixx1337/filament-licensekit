# Changelog

## Unreleased

- Trusted local application issuance with integration scope, explicit serial disclosure, and stable retry results.
- Replaceable key normalization and initial-schema support for 255-byte canonical keys with exact equality.
- Explicitly enabled historical entitlement, key, device, and reset imports with preflight reports and source fingerprints.
- Immutable claim provenance and after-commit `LicenseClaimed` events; synchronous outage hooks remain intact.
- Core catalog operations accept explicit actors independently of a Filament panel.
- UTC key/device timestamps. The initial schema includes all alpha changes; no incremental upgrade path is maintained for disposable development databases.

## 0.1.0-alpha.1 - 2026-10-15

Initial pre-release of LicenseKit.

- Licensing APIs for products, plans, license keys, entitlements, and device bindings.
- Filament administration with Laravel policy support.
- Optional outage compensation, downloads, free access, and licensing bans.
- Support for PHP 8.3+, Laravel 12 and 13, Filament 4.13.3+ and 5.8.3+, and Sanctum 4.x.

Pre-release APIs and configuration may change before the first stable release.
