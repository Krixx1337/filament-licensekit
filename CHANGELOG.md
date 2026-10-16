# Changelog

## Unreleased

- PC history: the first PC lock is now logged (`device_locked`). Each license page lists every lock, move and reset with who did it, the staff note and a short stable PC name (never the raw fingerprint). Licenses with 3 or more moves or resets in 30 days get a "Changes PC often" warning on their page and in the dashboard's Needs attention list. The activity log has a "PC changes only" switch.
- Connect your app offers each example client through **Copy full example** and **Download** buttons. When the host installs `phiki/phiki` (now listed under `suggest`), a highlighted five-line usage preview is shown too; without it no code is shown on the page.
- **Admin panel redesign (breaking for custom views):** the panel now uses only native Filament components, so it looks right without a host theme. New dashboard (filters, stats with trends, redemptions chart, licenses-by-status chart, needs-attention list, recent activity, get-started checklist). Sidebar groups Licensing, Protection and Setup replace the Settings hub page. Licenses and keys have status tabs with counts; licenses get a Manage menu, toasts that name the customer and a full license page (overview, PC lock with shared-PC warning, online now, keys, activity). Generate keys is a three-step wizard. Products have create and edit pages. Connect your app, Team access and the setup page are rebuilt as schema pages. Removed views: `dashboard`, `settings`, `connect-your-app`, `team-access`, `setup-needed`, `license-detail`, `result`; `ManageProducts` is replaced by `ListProducts`, `CreateProduct` and `EditProduct`; `LicenseResource::statusLabel()` is replaced by `LicenseState::for()`.
- **Fixes:** logging in from the loader clears that customer's expired loader sessions. `unsuspend()` no longer restores a cancelled license and `restore()` no longer lifts a suspension (`cannot_unsuspend_license`, `cannot_restore_license`). A passed timed suspension no longer blocks staff pause/resume or shows under the Suspended filter (`License::liftExpiredSuspension()`, `effectiveStatus` scope). Bulk **Suspend** skips licenses that are already suspended, so a timed suspension stays timed.
- **Simplification (breaking):** removed historical imports (`ImportLicensing`, snapshot DTOs, `licensekit_import_records`, `imports.*` config) and replaceable key normalization (`KeyNormalizer`, `CanonicalKeys`, `key_normalizer` config). `SerialKey::normalize()` is the single key parser again, and the key column is back to the standard 29-character format. Initial migration changed; rebuild disposable databases.
- **Simplification (breaking):** device locks are per product and stored on the license (`device`, `device_bound_at`, `device_reset_at`). The device is a plain string checked by a one-method `DeviceMatcher` (`device_matcher` config; default exact match). Products get `device_move_cooldown_days` (null = support only), replacing `device_binding.customer_reset.*`. `LicensedDevices` is the single device service: `bind()`, `verify()`, `moveDevice()`, `reset()`, `adminReset()` and `eligibility()`. Removed: the `device_bindings` and `device_reset_history` tables and admin page (resets are in the activity log), global scope, encrypted storage, `upgrade()`, `HwidHandler` (including the host `assertAllowed()` ban hook; built-in device bans are planned), `Hwids`, `HwidPayload` and `DeviceBindings`.
- **Simplification (breaking):** admin permissions are two gates, `licensekit.admin` (everything; every panel user by default) and `licensekit.support` (licenses, keys and reset history; suspend, add time, pause, reset devices). This replaces about 58 per-model abilities, the `licensekit.manage` fallback, the Shield recipe and `AdminAuthorization`. Filament still applies host model policies after the role check. PHP services no longer re-authorize the actor; they only record it. `ManageEntitlements::exportUnclaimedBatch()` no longer takes an actor.
- **Simplification (breaking):** removed reseller account ownership. API clients no longer have `owner_user_id` or retirement, batches no longer have `reseller_user_id`, and `ResellerInventory` and the `reseller:` issuance arguments are gone. An API client is the reseller: it reads the batches it created, and the Batches page filters by client.
- **Simplification (breaking):** licenses have one `status` (`active`, `paused`, `suspended`, `revoked`) plus optional `suspended_until`, replacing `is_revoked`, `is_blocked` and the separate licensing-ban table, model, admin page and `features.licensing_bans` flag. Bans become **Suspend** (`ManageEntitlements::suspend()`, or `suspendCustomer()` before a purchase); suspended licenses report `license_suspended`. Products have one `status` (`active`, `maintenance`, `discontinued`) replacing `is_enabled` and `is_blocked`; the Management API product field `is_enabled` becomes `status`. `ManageEntitlements::status()` is replaced by `suspend()`, `unsuspend()`, `revoke()` and `restore()`, with optional notes.
- **Simplification (breaking):** replaced the optional Outages module (incidents, compensation ledger, queued `ApplyCompensation` job, retry/cancel, `Service down` and `Added time` pages, `modules.outages` config and its migration) with a synchronous product **Add time** action (`ManageEntitlements::addTimeToProduct()`), which extends running licenses, banks time on paused ones and can include licenses that expired after a chosen moment. The product block toggle is now labelled **Maintenance mode**.
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
