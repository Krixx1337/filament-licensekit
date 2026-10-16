# Changelog

## Unreleased

- **Simplification (breaking):** removed historical imports (`ImportLicensing`, snapshot DTOs, `licensekit_import_records`, `imports.*` config) and replaceable key normalization (`KeyNormalizer`, `CanonicalKeys`, `key_normalizer` config). `SerialKey::normalize()` is the single key parser again, and the key column is back to the standard 29-character format. Initial migration changed; rebuild disposable databases.
- **Simplification (breaking):** removed product groups (`ProductGroup`, its admin page, product/plan group filters, `product_group_id`, and the Management API `product_group_id`/`group_name` fields). Initial migration changed; rebuild disposable databases.

- Reseller-owned batch access across clients and host PHP through `ResellerInventory`, with licensing counts and paginated key status reads. Existing key-list responses now use resource pagination, default 25 keys per page. Sales and delivery remain host-owned.

- Optional registered-user ownership of API clients, immutable batch attribution across API/admin/local issuance, and client/reseller administration filters.
- Owner eligibility checks for management operations and permanent audited client retirement before owner deletion. Initial migration changed; disposable development databases need rebuilding.
- Optional per-product HWID binding and explicit `bypassHwid` Gate/policy immunity, with payload validation and host device checks retained.
- Product administration toggle and management catalog `requires_hwid` field. The initial alpha migration includes the new default-enabled column; disposable development databases need rebuilding.

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
