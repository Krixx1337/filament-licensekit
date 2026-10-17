# LicenseKit simplification audit

Date: 2026-10-16 · Scope: `main` at `73f9644` (plus the untracked `docs/internal/`, since deleted per D9) · Status: implemented on `simplify/phase-1`; historical findings below retained for context.

## Implementation progress

- Phase 1 complete: cuts 1-8, License rename, nine-table fresh schema, batch duration snapshots.
- Phase 2 implemented: signed loader with restricted first-redemption sessions, Livewire/JSON portal, bans, free window, D10 rules, one issuance service, hashed connections and seller admin.
- Phase 3 implemented: setup page, no runtime readiness checks, narrower locks, one log API, two events, flat config, rewritten guides and Sanctum-free CI.
- Owner clarifications recorded in `docs/decisions.md`.
- Local validation: 207 Pest tests passed, 1,559 assertions; Pint, Larastan, 200-character source-line check, strict Composer validation, dependency audit, current-runtime smoke and distribution archive checks passed.
- Three MySQL race cases and CI compatibility/lowest-dependency jobs remain pending. Current-runtime smoke ran locally on Laravel 13.36.0 / PHP 8.4.26. No push or merge performed.

---

## 0. Owner decisions (2026-10-16)

These were settled after the first draft. The rest of the document follows them.

| # | Decision | Consequence |
|---|---|---|
| D1 | **Account-first.** The primary user is a game-tool or cheat seller who sells keys through a storefront or resellers. Every customer has an account and redeems keys into it. **Key-only activation (no account) is out of scope**, not an optional mode. | Keys stay vouchers. A license is one row per customer and product. Model B from the first draft is dropped. |
| D2 | **Customers redeem keys on the website *or* in the loader, after logging in.** Registration stays on the website, because the host app owns users. | One redeem operation (`ClaimLicense::claim($user, $key)`), reached from both places. |
| D3 | **Ship an ultra-simple default loader API (login, heartbeat, redeem) that sellers can turn off and replace.** | Thin controllers over public PHP services. A seller who needs custom behavior disables the routes and copies a short controller. See F23. |
| D4 | **Customers can fix their own device lock from the loader *and* from the seller's website, limited by a per-product cooldown. Support can always override.** | The loader *moves* the license to the PC it runs on, in one step. The website *clears* the lock, because a browser can't read a hardware ID, and the next loader login binds the new PC. Both share one cooldown. The package ships the website button as a drop-in component. See F4, F23 and F23a. |
| D5 | **LicenseKit has no email-verification setting of its own.** It follows the host's user model: if the model implements Laravel's `MustVerifyEmail`, unverified customers can't redeem or log in to the loader; otherwise nothing is checked. | Delete `licensekit.user.require_verified` and its diagnostics. Laravel starter kits leave `MustVerifyEmail` off, so in practice verification is off unless the seller enabled it for their website. A block always shows a clear `email_not_verified` message. |
| D6 | **Hardware (device) bans are a built-in feature.** Banned cheaters and chargeback abusers come back on new accounts, so sellers in this market expect to ban the PC, not just the account. | A small `device_bans` table and a "Ban device" action, enforced on loader login, heartbeat and move. Replaces the host-only `HwidHandler::assertAllowed` hook. See F4a. |
| D7 | **Free access is a per-product, scheduled "free weekend" window** (`free_from`, `free_until`), set in the admin. | Replaces the `freeUntil` PHP parameter. Accounts, device lock and bans still apply. See F5. |
| D8 | **Keep the current support matrix:** PHP 8.3+, Laravel 12 and 13, Filament 4.13.3+ and 5.8.3+. | CI keeps testing both Filament majors. Only the Sanctum-specific jobs go, once Sanctum is removed (F19). See T2. |
| D9 | **The temporary internal docs are deleted** (`docs/internal/`, done 2026-10-16). | Findings below that cite them are kept as history. Their content is replaced by the docs plan in §6. |
| D10 | **Keep pause/resume** ("vacation mode"), reversing the first draft. Freezing a subscription is a selling point in this market (competitors such as EngineOwning offer it). Removing outages and imports makes it cheap to keep. | `paused` becomes a license status, with `paused_at`, `paused_until` and `remaining_seconds`. Redemptions and bulk "add time" go into the banked time. See F6. |

---

## 1. Executive summary

LicenseKit's core idea is good and small: **products have plans, plans produce keys, keys grant time, and time is locked to a device.** The code around that idea has grown into a general framework for imagined operators. It has 15 tables, 12 admin screens, about 58 permission abilities, 71 error codes, 4 extension contracts, 11 DTOs, a queued compensation engine, a historical import system, and roughly 3,600 lines of specification-style documentation. Every one of these exists before a single user has asked for it.

The audit's main conclusion is that **the product does not yet serve its stated audience.** A non-technical seller cannot use LicenseKit today. They would have to:

1. own and run a Laravel app with Filament, Sanctum, policies and (optionally) Shield;
2. write their own desktop login and heartbeat controllers, because the package ships **no client API**;
3. build their own key-redemption page or loader endpoint, because the package only offers a PHP method;
4. learn twelve admin screens and terms like *entitlement, batch, issuer, reference, scope, HWID, compensation operation*.

The recommendations, in order of leverage:

| # | Move | Effect |
|---|------|--------|
| 1 | **Freeze features.** Keep the account-first model (D1) and simplify inside it. | Unblocks every other simplification. |
| 2 | **Ship a simple, replaceable loader API** (`login`, `heartbeat`, `redeem`) that works with no host PHP (D3). | Makes the product usable by the stated audience. |
| 3 | **Delete or shrink six speculative features**: outages engine, historical imports, product groups, local integration config, reseller ownership, and HWID upgrade/global scope/encryption modes. Pause/resume stays (D10). | About 45% of `src/` before new features, 5–7 tables, most edge cases. |
| 4 | **Merge overlapping "stop access" controls** (revoke, block, ban, pause, product block, product disable, outage block) into one status per license and one per product. | 7 concepts become 2 statuses (plus device bans). |
| 5 | **Replace the per-model permission matrix with two roles** (Admin, Support), and let Filament's native policy support handle the rest. | Removes `AdminAuthorization`, `AdminAccess`, actor re-locking in every service, and the Shield recipe. |
| 6 | **Rebuild the admin around 5 screens and plain language**: Dashboard, Licenses, Products, Key batches, Settings. | Matches the intuitive-UI requirement. |
| 7 | **Drop Sanctum**; use one hashed token column per API connection. | One fewer dependency and one fewer token-purpose problem. |
| 8 | **Remove runtime readiness checks** (`Schema::hasTable` and config validation on every request). Validate once in `licensekit:check`. | Faster and simpler hot path. |
| 9 | **Rewrite the docs**: 5 short task-oriented guides plus 1 decision log, replacing the internal "canonical document" system. | About 80% fewer doc lines, and no drift. |
| 10 | **Keep the support matrix (D8)**, but drop the Sanctum-specific CI jobs once Sanctum is gone. | Same compatibility, a slightly smaller matrix. |

Estimated result: **about 50% less source, about 45% fewer tables (16 to 9), about 80% fewer docs, and a 5-item admin nav**, with a product that works without writing PHP. These are estimates from reading the code, not measurements of a finished refactor.

---

## 2. What LicenseKit is today (understanding)

**Purpose (from the README and the former internal architecture notes):** a self-hosted alternative to KeyAuth or Keygen for indie sellers of desktop software, game mods and cheats. It provides key generation, redemption, HWID node-locking, reseller distribution and downtime compensation inside an existing Laravel and Filament app.

**Stated design principles:**
- The host owns users, authentication, client protocols, sessions, commerce and distribution. LicenseKit owns "rights" and device locks.
- Everything is reachable through PHP services. HTTP and Filament are thin adapters.
- Correctness is strict: atomic transactions, explicit locks, idempotent retries, immutable history, MySQL race tests.
- "No speculative frameworks." This is stated repeatedly in `feature-priorities.md`, but the code has drifted from it (see §4.3).

**Current domain flow (account-first):**

```
Admin/API/Store ──► Batch ──► LicenseKey (voucher)
                                   │ customer must log in on the website and "claim"
                                   ▼
Host user ──► Entitlement (user × product: expiry/lifetime/revoked/blocked/paused)
                                   │ host-written login controller calls LicensedDevices::bind()
                                   ▼
                           DeviceBinding (user × scope_key) + reset history
```

**Inventory (measured):**

| Metric | Value |
|---|---|
| `src/` PHP | 6,579 lines (≈305 KB with migrations/config). 62 lines exceed 200 characters. |
| Tests | 9,336 lines, including fixtures that simulate a host desktop protocol the package doesn't ship |
| Docs | 591 public + ≈1,490 internal lines + 1,561-line OpenAPI. `docs/internal/` was untracked and is now deleted (D9). |
| Tables | 12 core + 3 outages + Sanctum `personal_access_tokens` |
| Filament resources | 12, all in one "Licensing" nav group |
| Permission abilities | ≈58 (standard plus custom), plus the `bypassHwid` and `licensekit.manage` gates |
| Distinct error codes | 71 |
| Extension contracts | `HwidHandler` (4 methods), `KeyNormalizer`, `AccessRestriction`, `ReadinessCheck` |
| Issuance entry points | 4: `CreateBatch::create` (internal), `createFor`, `ManagementOperations::create`, `IssueLicenses::issue` |
| Ways to stop a customer's access | 7: revoke, block, ban, pause, product disable, product block, blocking outage. Plus host suspension and `AccessRestriction`. |
| CI | 9-combination matrix + 2 lowest-runtime jobs + 1 MySQL job |

---

## 3. Strategic findings

### S1. The product's audience and its architecture point in opposite directions — **P0**

The brief says the admin "must be extremely intuitive for non-technical users who simply want to sell and manage software licenses". The architecture says the opposite: *"The host owns client authentication, credentials, endpoints, payloads and sessions"* (`desktop-protocols.md`). Today a seller must write PHP controllers, transactions with explicit user locks, and invalidation listeners before one customer can launch the software. `docs/client.md` shows the required code, and `desktop-protocols.md` adds atomic-login rules ("Lock the verified user, recheck credential freshness…").

**Recommendation:** decide who the product is for.
- **If it is for non-technical sellers (recommended, and matching the brief):** ship a default loader API (decided in D3). Keep the PHP services as an advanced extension point, not the only path.
- **If it is for Laravel developers:** say so in the README, and stop claiming "turnkey". Even then, most of §4 still applies.

### S2. Activation model — **decided: account-first (D1)**

The first draft proposed a key-first model, where the key itself is the license. The owner rejected it: every customer has an account, and keys are redeemed into it. That is a coherent model with real benefits for this market. Password plus device lock limits sharing, renewals stack, one login covers several products, and there is a natural customer portal.

What the decision still requires:
- **Redemption must be possible in the loader, not only on the website (D2).** A buyer from a Telegram reseller should be able to log in to the loader and paste the key, without hunting for a website page. Registration stays on the website.
- **Keep the model, simplify inside it.** "Entitlement" becomes **License** (one row per customer and product). The device lock moves onto that row, because it has the same cardinality (§4.1 F4, F7). Keys stay a separate table of vouchers.
- **Email verification is the host's decision, not LicenseKit's (D5).** Today `require_verified` defaults to `true`, so a seller who never configured outgoing mail ends up with every customer blocked as `user_unavailable` and no explanation. Remove the setting and follow the host user model's `MustVerifyEmail` instead (§5 U7).

### S3. Features were built ahead of demand, against the project's own rules — **P0**

`feature-priorities.md` lists these as deferred or not worth building:
- **"Freezable subscriptions remain deferred."** Pause and resume are implemented (`EntitlementClock::pause/resume`, `paused_at`, `remaining_seconds`, and checks in claim, access, compensation, import and summaries).
- **"Migrate credentials or rights from another system — Outside v1; evaluate only for a concrete migration."** A full import API is implemented: `ImportLicensing` (285 lines), 5 DTOs, an `import_records` table, HMAC fingerprints, and a pluggable `KeyNormalizer` with a 255-byte ASCII-bin key column.
- **"Placeholder… frameworks… added only for imagined future consumers."** Reseller ownership, client retirement, owner-immutability guards and `ResellerInventory` were added in the last three commits, with no consumer.

The code is careful and well tested. The problem is cost: each feature multiplies the edge cases of every other one. For example, `ManageEntitlements::changeClock` must consult `OutageState::assertClockChange`, which exists only because outages and pause both exist.

**Recommendation:** freeze new features. Apply the project's own rule ("add boundaries with a working implementation") retroactively, and delete what fails it (§4.3).

### S4. Documentation became a second codebase — **P1**

The internal docs (now deleted, D9) used a "canonical source-of-truth" governance system with precedence rules between documents. They read like legal specifications: dense paragraphs that list every invariant and race. They have already drifted:

- They reference `apps/starter`, starter routes like `/api/licensekit/v1/products/{product:slug}/login`, and `todo/old/lean-v1-plan.md`. None of these exist in the repository (17 references).
- `management-api.md`'s curl example sends `"method":"on_demand"`, which `ManagementWriteRequest` rejects with 422 (`'method' => ['missing']`).
- `licensing.md` says *"There is no stock pool, import operation…"*, but `docs/importing.md` documents imports.
- The ability table in `filament-pivot.md` omits `extend`, `pause`, `resume`, `retire` and `bypassHwid`. `docs/installation.md` includes them.
- `development.md` says support is "PHP 8.4, Laravel 13.35+, Filament 5.10+". The README and CI say PHP 8.3, Laravel 12/13, and Filament 4.13.3+/5.8.3+.
- The README promises a "Turnkey dashboard". There is no dashboard page or widget.

**Recommendation:** see §6.

---

## 4. Detailed findings and recommendations

Each item lists the finding, the recommendation, a priority (P0 = do first, P1 = next, P2 = polish), an effort (S/M/L), and what it removes.

### 4.1 Domain model and schema

**F1. Seven overlapping ways to stop access.** These are entitlement `is_revoked` ("Cancel license"), `is_blocked` ("Block access"), `paused_at` ("Pause"), the `LicensingBan` table (product-scoped, immutable, with expiry), product `is_enabled`, product `is_blocked` ("Manual maintenance block"), and outages with `access_blocked`. On top of these sit host `suspended_column` and `AccessRestriction` classes. Each has its own error code (`license_unavailable`, `service_unavailable`, `subscription_paused`, `license_banned`, `outage_processing`…), its own UI and its own docs.
→ **One license status and one product status.**
- License: `active | paused | suspended | revoked`, plus an optional `suspended_until` and a note. `paused` is the customer-friendly freeze kept by D10 (F6); the other three replace revoke, block and bans. "Ban user from product" is simply *suspend their license*, with history in the activity log. Banning someone who has no license yet creates their license row in `suspended` state, so the ban also blocks redemption. The license row represents the customer's relationship with the product, even before any paid time exists.
- Product: `active | maintenance | discontinued`. "Maintenance" replaces both `is_blocked` and blocking outages.
P0 · M · removes `licensekit_licensing_bans`, `ManageLicensingBans`, `LicensingBans`, `LicensingBanResource`, `features.licensing_bans`, and `OutageState::assertAccess`.

**F2. Redundant snapshots and attribution columns.**
- `duration_days` is stored on plan, batch *and* key. The key copy exists only for imports.
- `license_keys.license_plan_id` duplicates `batch.license_plan_id`.
- Batches carry `issuer`, `reference`, `api_client_id`, `created_by_user_id` and `reseller_user_id`. That is four ways to say who made the batch.
→ Key/license → batch → plan, with one `duration_days` snapshot on the batch. Batch attribution becomes `source` (`admin|api|app`), `created_by` (a display string) and a nullable `api_connection_id`. Keep `reference` only for API idempotency, and auto-generate it in the admin.
P1 · M.

**F3. Product groups are metadata with no behavior.** The docs confirm it: "Groups confer no license rights… outages target products only." They still bring a table, a resource, filters on two screens, audited delete logic, a diagnostics check and a docs section.
→ Delete. If organization is needed later, add a free-text `category` column on products.
P0 · S · removes 1 table, 1 resource, 2 filters, and `CatalogWrites::deleteGroup`.

**F4. Device binding is over-generalized.** It has two scopes (`global` and `per_product`) selected by config, with no conversion. It has two storage modes (`plain` and `encrypted`), also with no conversion. A runtime-agnostic JSON payload is validated to 8 KB and 128 levels deep. There is a 4-method handler (`normalize`, `matches`, `canUpgrade`, `assertAllowed`), an explicit `upgrade()` flow, a per-product `requires_hwid` flag, a `bypassHwid` gate, customer reset with `enabled`/`cooldown_days`/`require_reason`, admin reset, and a separate immutable reset-history table. A diagnostic table scan detects scope flips.
→ Simplify:
- **Per-product only, stored on the license row.** A license is already unique per `(user_id, product_id)`, so `device`, `device_bound_at` and `device_reset_at` become license columns. This deletes the `device_bindings` table, `scope_key`, the global mode and the scope-flip diagnostics.
- **The device is a string** (the client sends a fingerprint). Allow an optional JSON payload only if a custom matcher is configured.
- **Replace `HwidHandler` with `DeviceMatcher::matches(string $bound, string $presented, Product $product): bool`.** Hardware bans become a built-in feature instead of a matcher duty (F4a). Delete `canUpgrade`, `assertAllowed` and `upgrade()`. A legitimate hardware change is a reset, and fuzzy matching already covers drift.
- **Pick one storage mode** (plain). Fingerprints are not secrets, and a mode that can never be changed is a trap.
- **Reset history goes into the activity log**, keeping the cleared device in the event metadata.
- **Self-service device changes (D4) become one per-product admin setting**, not three config keys: *"Customers can move their license to a new PC: Never (support only) · every 1 / 7 / 14 / 30 days"*. The loader's "move" and the website's "reset" share this cooldown, stored as `device_reset_at` on the license. Admin reset ignores it. Drop `customer_reset.enabled`, `cooldown_days` and `require_reason`.
- Every move or reset is logged with the old device, so support can spot accounts that keep hopping between PCs (the usual sign of account sharing).
P0/P1 · M · removes `licensekit_device_reset_history`, `DeviceResetHistoryResource`, the `Hwids` wrapper, `HwidPayload` validation depth, `upgrade()`, and 3 config keys.

**F4a. Built-in device bans (D6).** Today LicenseKit ships no hardware-ban storage or UI. A host has to implement `HwidHandler::assertAllowed()` against its own blacklist. That is a real feature in this market, so build the simplest useful version:
- **Table** `device_bans`: `id, product_id? (null = all products), device, device_hash (indexed), note?, created_by, created_at`. Unban deletes the row; the activity log keeps the history.
- **Enforcement:** loader `login`, `heartbeat` and `move-device` reject a matching device with `device_banned`. Matching is on the exact fingerprint string the loader sends (by hash, so it's one indexed lookup). A loader that sends structured data should ban on the matcher's normalized form.
- **Admin:**
  - A **Ban device** action on a license uses that license's current device. Options are *this product* or *all products*, plus *also suspend this license* (on by default).
  - The confirmation lists **other accounts bound to the same device** ("This PC is also used by 2 other accounts: …"), each with an option to suspend it too. That catches alt accounts for the cost of one query, and it works because devices are stored in plain text (F4).
  - A **Device bans** list sits next to Licenses, with search and Unban.
- **Honest limit:** HWID spoofers can defeat any fingerprint ban. This is a speed bump, which is what sellers in this scene expect, not a guarantee.
P1 · M · adds 1 table, 1 small resource and 1 check; removes `HwidHandler::assertAllowed` and the host-blacklist documentation.

**F5. Free access via a `freeUntil` parameter** is threaded through `AccessPolicy`, `LicensedDevices::bind/verify/upgrade` and the docs.
→ **A scheduled per-product free window (D7)**: product `free_from` and `free_until`, set in the admin as *"Free weekend: Fri 18:00 → Sun 23:59"*. Sellers can schedule it ahead and it ends by itself. During the window any logged-in customer without paid time is allowed. Their license row is created on first login, so the device lock, device bans and suspensions still apply. Paid customers keep their own clock, and keys redeemed during the window stack normally. Delete the `freeUntil` PHP parameter. Per-user trials are out of scope until someone asks.
P1 · S.

**F6. Pause and resume — keep, with friendlier rules (D10).**
The first draft recommended deleting this. The owner kept it, because "freeze my days while I'm on holiday" is a selling point in this market, and it is distinct from *Suspend* ("you broke the rules"). Most of its cost came from interactions with outages, the compensation ledger and imports, all of which are removed. What remains touches four places: the access check, redemption, bulk add time and the license summary.

Today's implementation has customer-hostile rules. The new ones:

| Situation | Today | New rule |
|---|---|---|
| Customer redeems a key while paused | Rejected with `subscription_paused` | Accepted. The key's time is added to `remaining_seconds`. |
| Product-wide "Add time" | Skips paused licenses | Paused licenses get it added to `remaining_seconds`. |
| Staff forgets to resume | Paused forever | An optional **"Pause until"** date (`paused_until`). On the customer's first login or heartbeat after it, the license resumes automatically: `expires_at = now + remaining_seconds`. No scheduler is needed. |
| Shown in the admin | "Paused at" column + "Banked time" column | One status badge: **Paused (18 days left)**, with the resume date when set. |

Other rules carry over: lifetime and cancelled licenses can't be paused. A paused license can still be suspended, and suspension wins. During a free weekend (D7), a paused customer gets free access while their banked days stay untouched. Pausing ends that license's loader sessions.

Pausing is a staff action from the license page, with a reason that is optional, like every other note. Customer self-service freezing (e.g. "once per 30 days, at most 14 days") is a possible later feature, not part of this plan.
P1 · S · about 100–150 lines kept; `subscription_paused` becomes the loader error `paused`.

**F7. Target schema (account-first).**

```
products         id, name, slug, status(active|maintenance|discontinued), device_lock bool,
                 device_move_cooldown_days? (null = support only), free_from?, free_until?, timestamps
plans            id, product_id, name, duration_days? (null = lifetime), is_active, timestamps
batches          id, plan_id, duration_days?, quantity, label?, source(admin|api|app), created_by,
                 api_connection_id?, reference, timestamps · unique(source, api_connection_id, reference)
keys             id, key unique, batch_id, license_id?, redeemed_at?, cancelled_at?, note?, timestamps
licenses         id, user_id, product_id, status(active|paused|suspended|revoked), suspended_until?,
                 is_lifetime, expires_at?, paused_at?, paused_until?, remaining_seconds?,
                 device?, device_bound_at?, device_reset_at?, note?, timestamps
                 unique(user_id, product_id)
loader_sessions  id, token_hash unique, user_id, product_id, device, expires_at, last_seen_at, created_at
device_bans      id, product_id? (null = all), device, device_hash index, note?, created_by, created_at
api_connections  id, name, token_hash, is_active, allowed_product_ids json?, max_per_request, last_used_at?, timestamps
activity_log     id, actor, event, subject_type, subject_id, meta json, created_at
```

That is 9 tables instead of 16, with no Sanctum table. `loader_sessions` exists only for the default loader API (F23), and a seller who replaces that API can ignore it.

How the main operations map onto it:
- **Redeem:** lock the key row, then find or create the license for `(user, product)`. Add the batch's `duration_days` to `max(now, expires_at)`, or to `remaining_seconds` if paused, or set lifetime. Mark the key redeemed. Unique constraints cover first-redemption races.
- **Access check:** the product is `active`; the license is `active`; and its time is remaining or lifetime, or now is inside the product's free window.
- **Device:** the presented device is not in `device_bans` for this product or all products. If `device_lock` is on, the first login sets `device` and later logins must match. Reset clears it, subject to the cooldown.

### 4.2 Issuance and keys

**F8. Four issuance paths with layered authorization.**
- `CreateBatch::create` (marked `@internal`, but public).
- `createFor` (admin; locks actor, reseller, product and plan, then calls `create`, which locks product and plan again).
- `ManagementOperations::create` (API; wraps `ManagementAccess::run`).
- `IssueLicenses::issue` (config-defined "local integrations" with their own product scope, quantity cap and serial-disclosure flag).

→ **One service: `Keys::issue(Plan $plan, int $quantity, IssueContext $ctx): Batch`.** Authorization happens at the edges: the Filament action checks the admin, the API middleware checks the connection, and trusted app code just calls the service. Delete the `local_integrations` config. Its own docs admit it is "operational discipline… not a sandbox", so trusted code needs no permission system.
P1 · M · removes `IssueLicenses`, `IssuanceResult`, `ManagementOperations`, and the `local_integrations` config.

**F9. `KeyNormalizer` and `CanonicalKeys` exist for custom imported key formats.** They force a 255-byte `ascii_bin` key column, double normalization, idempotence checks and the `invalid_key_normalizer` error, and `SerialKey::normalize` is already deprecated.
→ Delete them along with imports. Keep one `SerialKey` class with `generate()` and `normalize()`.
P0 · S.

**F10. Historical imports** (see S3).
→ Delete `ImportLicensing`, the 5 snapshot DTOs, `licensekit_import_records`, `imports.*` config and `docs/importing.md`. If a migration customer appears, write a one-off artisan command or add a CSV import of keys in the admin.
P0 · S · about 500 lines including DTOs and tests.

**F11. Generation** is good (chunked inserts, collision retry through the unique index). Keep it.

### 4.3 Optional modules

**F12. The outages and compensation engine is the largest complexity cluster.** It includes `ManageOutages` (241 lines), the `ApplyCompensation` queued job with transient-error classification, 3 tables, per-entitlement snapshot ledgers, a synchronous `EntitlementGranted` listener that adds mid-incident purchases to the ledger, `pending/processing/failed/canceled/completed` states, retry and cancel, `outage_processing` claim refusals, the `OutageState` cross-module guard, 2 admin resources, the module flag, migrations loaded conditionally, and runtime `Schema::hasTable` calls.

For the stated user, the actual requirement is "the server was down for 6 hours, so give everyone 6 hours back".
→ Replace it with **one product action: "Add time to active licenses"**. It takes a duration, an optional "include licenses that expired since…" date, a live preview count and a confirmation. It runs one synchronous `UPDATE … SET expires_at = expires_at + INTERVAL ?` in a transaction and writes one activity-log entry with the count. Even 100k rows is a single statement and needs no queue.

Sellers on shared hosting often don't run queue workers, which today leaves operations stuck as "pending". Product **maintenance mode** (F1) covers "block access during downtime". Delete the module system (`Modules`, `ModuleReadiness`, `modules.*` config) because no optional module would remain.
P0 · M · removes about 700 lines of src, 3 tables, 2 resources, 1 job, 1 listener, 1 event, and the module framework.

**F13. Reseller ownership** (last three commits): `api_clients.owner_user_id` (immutable, with restrict-on-delete), `retired_at` plus a retire flow, owner-eligibility checks on every API call, `batches.reseller_user_id`, `ResellerInventory` for host reseller pages, and a reseller select in Create keys.
→ **An API connection *is* the reseller or store**. A batch records which connection created it. The admin's "Key batches" list filters by connection, and **Export** and **Cancel unused keys** cover reseller disputes, which is the README's selling point. Delete host-user ownership, retirement, owner-immutability guards and `ResellerInventory`. If resellers need a self-service portal later, design it as a feature.
P0 · S/M.

**F14. `AccessRestriction` contract + `access_restrictions` config.** A host that needs extra checks can wrap the service call, or listen for an event.
→ Delete. P1 · S.

### 4.4 Authorization

**F15. A bespoke authorization layer duplicates Filament and Laravel.**
- About 58 abilities across 12 models. Each custom ability (`createKeys`, `exportUnclaimed`, `revokeUnclaimed`, `restoreAccess`, `resetHwid`, `inspectDevice`, `startOutage`, `addTime`, `rotateToken`, `retire`, …) must be mapped by hand in a Shield config recipe.
- `AdminAuthorization::allows()` uses the policy if one exists and otherwise falls back to `licensekit.manage`. `allowsExplicit()` exists for `bypassHwid`.
- `AdminAccess` re-resolves the panel, plugin, resource registration and feature flags, caches the actor per request, and calls `canAccessPanel`.
- `LicensingResource` overrides `getAuthorizationResponse` and every `can*` method.
- **Every core service re-loads and row-locks the actor inside its transaction and re-checks the ability.** `ClaimLicense::claimFor` locks actor and user together sorted by key. `ManageApiClients::authorize` share-locks actor and owner. `ManageEntitlements` re-locks the actor twice (in the transaction and again in `authorize`).

This guards against "permission revoked after the modal opened", a risk with negligible real impact for a solo seller. The price is high: every service signature takes `$actor`, every service does lock choreography, and the Shield recipe is a page long.

→ **Two gates, `licensekit.admin` and `licensekit.support`**, defined by the host or defaulting to "any panel user is admin".
- Support can view licenses, add time, suspend and resume, and reset devices.
- Admin can do everything.

Use Filament's standard resource policies and action `->authorize()` with those gates. Hosts using Shield still get standard model policies, because Filament checks them natively. Core services take an optional `actor` **for attribution only** and do no authorization. Their callers (Filament actions, API middleware, host code) are trusted. Delete `AdminAuthorization`, `AdminAccess` (beyond a tiny `actor()` helper), the `LicensingResource` overrides, `licensekit.manage`, `bypassHwid` (use the Support gate if exemption is needed, or drop it), and the per-ability Shield recipe.
P0 · M · removes hundreds of lines and about 40 tests' worth of permission permutations.

### 4.5 Transactions, locking and runtime checks

**F16. Pessimistic locking everywhere.**
- Every claim, batch creation, revocation, note edit and clock change does `Product::whereKey(...)->lockForUpdate()`. That is effectively a **per-product mutex**, and on a product launch every redemption serializes on one row.
- Reads inside transactions conditionally take shared locks on user, product, entitlement, binding and ban (`->when(DB::transactionLevel() > 0, fn ($q) => $q->sharedLock())`, repeated about 10 times).
- Most product locks exist to coordinate with outages, which F12 removes.

→ Lock only the rows being mutated: the key and the license. Rely on unique constraints for creation races (batch reference, key, `(user, product)`). Drop the product lock and the conditional shared locks. Keep **one** MySQL concurrency test each for redemption and for idempotent issuance.
P1 · M · is correctness-sensitive, so make the change only alongside those tests.

**F17. Configuration and schema validation on the hot path.**
- `EnsureConfigured` middleware runs `SetupDiagnostics::configurationErrors()` on every management request. That builds a user provider, resolves the encrypter and validates about 20 keys.
- `AccessPolicy` calls `ReadinessCheck::assertReady()`, which runs the same validation again, on every access check.
- `OutageState::assertAccess/assertClaim/assertClockChange` call **`Schema::hasTable()` on every access check, claim and clock change**. That is an information-schema query per call.
- `DeviceBindings::resetBinding` checks `Schema::hasTable('licensekit_device_reset_history')`.
- `LicenseKitPlugin::register()` silently registers **no resources** when config is invalid. A non-technical user sees an empty admin and gets no explanation.

→ Validate config once in `licensekit:check` and at install time. Fail loudly at boot only for genuinely fatal values. Never call `Schema::hasTable` at runtime. In the panel, show a **"Setup needed"** page that lists the problems instead of hiding everything.
P1 · S · removes `ReadinessCheck`, `ModuleReadiness`, `EnsureConfigured`, most of `SetupDiagnostics`, and `OutageState`.

**F18. A custom deep config merge** (`LicenseKitServiceProvider::mergeConfiguration`) exists because the config is deeply nested.
→ Flatten the config (§4.9) and use `mergeConfigFrom`. P2 · S.

### 4.6 Management API and tokens

**F19. Sanctum is used only for API-client tokens.** That brings token "purpose" checks (`name === 'licensekit:api'`, a `licensekit:api` ability), intersection of token abilities with client permissions ("adding permission requires rotation"), expiry handling, a polymorphic `tokenable`, and a hard dependency with version constraints per Laravel version (`^4.1` on Laravel 12, `4.3.1+` on 13).
→ Store `token_hash` (SHA-256) on `api_connections`. Show the token once at creation, and offer "Regenerate token". Expiry is optional. Permissions are read from the connection row only.
P1 · S · removes the `laravel/sanctum` dependency and the cross-purpose token problems.

**F20. Permissions are over-specified.** There are 3 scopes (`licenses:create`, `keys:read`, `batches:read-all`), a product allowlist with an "all products incl. future" flag, a per-request max, and owner visibility rules.
→ A connection can **create keys for its allowed products and read back the batches it created**. Keep the product allowlist and the max per request. Drop `batches:read-all` (admins use the panel) and the `keys:read` split. A connection that can create keys can already see them in the create response.
P1 · S.

**F21. Routing config** includes `enabled`, `prefix`, `domain`, `middleware` and `rate_limits.reads/writes`, all validated by `SetupDiagnostics::managementErrors()`.
→ Keep `enabled` and `prefix`. Use a fixed sensible rate limit, which hosts can override with Laravel's `RateLimiter::for` like any app. Drop `domain` and `middleware`.
P2 · S.

**F22. Small defects.**
- `BatchController::store` wraps `ManagementOperations::create` in `$access->run(...)`, and `create` calls `run` again. This means double authentication, nested transactions and two client row locks per request.
- `Core\Http\Resources\EntitlementResource` is unused.

P1 · S.

**F23. Add a simple, replaceable loader API** (S1, D3).

```
POST /api/licensekit/{product}/login      {login, password, device}  → {token, expires_at, license}
POST /api/licensekit/{product}/heartbeat  {token, device}            → {expires_at, license}
POST /api/licensekit/{product}/redeem     {token, key}               → {license}
POST /api/licensekit/{product}/move-device {login, password, device} → {token, expires_at, license}
```

- **Login** checks credentials against the host's existing Laravel auth provider (`retrieveByCredentials` and `validateCredentials`). LicenseKit never owns passwords or the users table. It then checks the account, the license and the device lock, and creates a `loader_sessions` row with a hashed token.
- **Heartbeat** finds the session, rechecks the license and device, and extends the session.
- **Redeem** calls the same `ClaimLicense::claim($user, $key)` the website uses (D2).
- **Move device** (D4) is for the moment a customer opens the loader on a new PC. Login returns `device_mismatch` with `can_move` or `move_available_at`. The loader asks *"This account is locked to another PC. Move it to this PC?"*, and this call rechecks the password and the product's cooldown. It then replaces the device, ends the old PC's sessions and returns a normal session. That is one step instead of "reset, then log in again". It uses the same `Licenses::moveDevice()` service as everything else.
- `license` in responses is a small fixed shape: `{status, expires_at, lifetime}`.
- Errors use a short fixed vocabulary: `invalid_credentials`, `no_license`, `expired`, `suspended`, `device_mismatch`, `device_banned`, `paused`, `move_cooldown`, `email_not_verified` (only when the host requires verification, D5), `maintenance`, `invalid_key`, `key_already_used`.
- Login is rate-limited per IP and login name. Several sessions on the same device are allowed. Website 2FA does not apply to loader logins; document that.

**Customization stays deliberately simple.** There are no hooks, adapters or protocol framework:
1. Config `client.enabled` (default `true`), `client.prefix`, and `client.login_column` (`email` or `username`).
2. Each controller is about 20 lines over public services: `LoaderSessions::login()`, `LoaderSessions::heartbeat()` and `ClaimLicense::claim()`. A seller who needs extra response fields or a different protocol sets `client.enabled = false` and copies the controller from `docs/client-api.md` into their app.
3. `AccessRevoked` (F25) lets the default sessions be deleted when a license is suspended, revoked or device-reset. Custom protocols listen to the same event.

Show the endpoints and copy-paste loader snippets (C++, C#, Python, curl) on a **"Connect your app"** page in the admin (§5). **Signed responses** matter in this market, because people emulate auth servers and patch loaders. Add an Ed25519 signature over each response body, with the public key shown on that page for embedding in the loader. This is small enough to include in the first version of the loader API.
P0 · M.

**F23a. A drop-in "My licenses" component for the seller's website** (D2, D4).
Sellers need a place on their own site where a logged-in customer can redeem a key and reset their device. Today they would build that page from PHP calls. Host frontends vary (Blade, Livewire, Inertia with React or Vue), so offer three layers, from least to most work for the seller:

1. **Livewire component, zero code:** `<livewire:licensekit.my-licenses />`. Livewire is already installed, because Filament requires it. For the authenticated user it shows each license (product, status, expiry, device locked or not), a **Redeem a key** box, and a **Reset device** button per license. The button shows *"Next reset available in 4 days"* during the cooldown and asks for confirmation (*"Your current PC will be signed out. The next PC you log in from becomes your device."*). If the host requires verified email (D5) and the customer hasn't verified, it shows *"Verify your email to redeem keys"* with a resend link instead of a generic error. It uses minimal Tailwind classes, and the views can be published for restyling.
2. **JSON routes for SPA hosts (optional):** `GET /account/licenses`, `POST /account/redeem`, `POST /account/licenses/{license}/reset-device`. They run under the host's `web` + `auth` middleware with CSRF, and are off by default (`portal_api.enabled`).
3. **PHP services for fully custom pages:** `CustomerLicenses::for($user)`, `ClaimLicense::claim($user, $key)` and `Licenses::resetDevice($user, $product)`. Each is a one-line call from a host controller.

All three go through the same services as the loader and the admin, so cooldown, logging and session cleanup behave identically wherever the button lives. Website reset *clears* the lock instead of moving it, because a browser can't read a hardware ID.
P1 · M · replaces "host must build redeem and reset pages" with one tag.

### 4.7 Events and audit

**F24. Two audit mechanisms.** One is explicit `Audit::record` calls in services. The other is a `LicensingConfigurationObserver` on Product/Plan/Group that reads a public `$auditActor` property planted on the model, falling back to `auth()->id()`. On top of these, the separate reset-history table is a third, specialized log.
→ One `ActivityLog::record($actor, $event, $subject, $meta)` called from services. Drop the observer and the `auditActor` properties. Store reset details as metadata. Show the log as a readable timeline ("Alex added 3 days to license Q7M4K-…").
P1 · S.

**F25. Four events plus provenance DTOs.**
- `EntitlementGranted` is synchronous inside the claim transaction and exists for the outages ledger.
- `LicenseClaimed` is dispatched after commit with a `ClaimProvenance` DTO, which `ClaimLicense` builds **twice**.
- `ProductAccessInvalidated` and `EntitlementAccessInvalidated` are separate events.

→ Keep two: `KeyRedeemed` (after commit, for delivery emails or Discord notifications) and `AccessRevoked(license)` (synchronous, so sessions are deleted in the same transaction). Delete `EntitlementGranted` along with F12, and pass models or IDs rather than snapshot DTOs.
P1 · S.

### 4.8 Code-level cleanup

| Item | Action | P |
|---|---|---|
| `Filament\CatalogWrites` wraps `Core\Actions\CatalogWrites`, a static class that re-validates what the Filament forms already validate | Use standard Filament Create/Edit actions with model validation. Delete both. | P1 |
| `DeviceResetEligibility` is a one-line wrapper around `DeviceBindings::eligibility` | Delete | P2 |
| `SerialKey::normalize` is deprecated | Delete (no users) | P0 |
| `AccessAuthorization::summary()` is used only by a test fixture | Delete | P2 |
| 62 source lines over 200 characters (e.g. `LicensingActions::userSelect`, the ability chains in `ModuleReadiness`) | Add a Pint/PHPCS line-length rule and split long fluent chains | P2 |
| 71 error codes, many single-use (`invalid_revocation_reason`, `invalid_reset_reason`, `invalid_key_note`, `invalid_reason`…) | Use Laravel validation for input shape; keep about 15 domain codes | P1 |
| `LicensingException` defaults to HTTP 403 for every code | Set an explicit status per code in an enum | P2 |
| `ManageEntitlements` mixes clock changes, status, key revocation, notes, batch export and batch revocation | Split by subject (license vs batch), or let it shrink naturally after F1/F6/F12 | P1 |
| `.gitattributes` references `/apps`, `/packages`, and `browser-tests.yml`, none of which exist | Clean up | P2 |
| Root clutter: `repomix-output.xml` (387 KB), `opencode.json`, `boost.json` (ignored, but present) | Delete local artifacts | P2 |

### 4.9 Configuration

Today there are about 30 leaf keys across nested arrays: `key_normalizer`, `local_integrations`, `imports.*`, `features.licensing_bans`, `modules.outages`, `device_binding.{scope,storage,customer_reset.{enabled,cooldown_days,require_reason}}`, `management.{enabled,prefix,domain,middleware,rate_limits.*}`, `user.{guard,display_column,suspended_column,require_verified}`, `access_restrictions`, `maximum_batch_size` and `hwid_handler`. Several of them can never be changed after install.

Target:

```php
return [
    'client' => ['enabled' => true, 'prefix' => 'api/licensekit', 'login_column' => 'email'],
    'store_api' => ['enabled' => false, 'prefix' => 'api/licensekit/store'],
    'portal_api' => ['enabled' => false, 'prefix' => 'account'],   // JSON routes for SPA hosts (F23a)
    'device_matcher' => \LicenseKit\ExactDeviceMatcher::class,
    'max_batch_size' => 1000,
    'user_label' => 'email',           // shown in the admin; user model comes from the auth provider
];
```

Anything a seller might change at runtime (free weekend, maintenance, device lock) becomes a **per-product admin setting**, not config.
P1 · S.

---

## 5. Admin panel UI/UX

The brief makes this the priority: a non-technical seller must be able to run their business from this panel.

### 5.1 Findings

**U1. Too many screens, organized by database table, not by task.** There are 12 nav items in one "Licensing" group with no icons or ordering: Product groups, Products, Plans, Keys, Batches, Licenses, API clients, HWID reset history, Change log, Bans, Service down, Added time. A seller's daily questions are "make keys", "look up a customer or key", "fix a customer's problem" and "how am I doing?". None of them maps to a screen.

**U2. Core tasks are buried or take many steps.**
- **Creating keys** is a row action on the *Plans* table, which is undiscoverable. The form asks for a **Reference** prefilled with a UUID, which is meaningless to a seller.
- **Giving a customer a license by hand** under the current model takes four steps: Plans → Create keys (qty 1, no download) → Keys → find the key → "Give key to user".
- **Looking up a customer's key** works by searching Keys. The *Licenses* screen can't be searched by key, and Keys can't show the owner. Checking whether a customer is locked to a device means going to Licenses, toggling the hidden "Device linked" column, and using "Inspect device" to read raw JSON.

**U3. Jargon leaks everywhere.** Entitlement/License/Key/Batch/Plan; issuer, reference and "Original quantity"; "HWID", scope and "Device cleared" JSON; "Manual maintenance block" versus "Enabled" versus "Mark service down/Block access"; "Cancel license" versus "Block access" versus "Pause" versus "Ban"; "Rotate management token"; and permission options like "Read other issuers' batches". Error toasts are the error code with underscores replaced: `ucfirst(str_replace('_',' ', $error))` produces "Admin forbidden", "Outage processing" and "Invalid key normalizer".

**U4. Mandatory reasons on everything.** Revoke, block, unblock, extend, pause, resume, reset, revoke batch, ban, lift ban, retire and outage all require a reason textarea. That is friction for a solo seller, and the activity log already records who and when.

**U5. Times in UTC.** Labels like "Expiry (UTC)", "Incident started at (UTC)" and "Reset at (UTC)" appear throughout. Sellers think in local time.

**U6. No dashboard, onboarding or empty-state guidance.** There are no counts (active licenses, unused keys, activations, expiring soon) and no "create your first product" path. Nothing tells the seller how to connect their app; that lives only in `docs/client.md`, as PHP.

**U7. A silent failure mode.** Invalid config or a tenant panel causes the plugin to register zero resources, so the seller sees nothing. Email verification defaults to required, so unverified customers get `user_unavailable` with no hint why. D5 removes LicenseKit's own setting, and the admin's "Check a customer" box should show *"Blocked: email not verified"* when the host requires it.

**U8. Status is shown as a row of booleans.** The Licenses table shows separate "Cancelled", "Blocked" and "Lifetime" icon columns, "Ends" and "Banked time". The seller has to work out the actual state from those.

**U9. API client form complexity.** It has an Owner/reseller select whose choice is permanent, "All products incl. future", a multi-select of scope strings, "Maximum keys per request", and separate actions for rotate, revoke and retire. Creating a client triggers a file download named `management-token.txt`.

### 5.2 Target information architecture

```
Dashboard        stats + "Generate keys" + "Find customer or key" + setup checklist
Licenses ▸       Licenses (one row per customer × product: where you fix a customer's problem) · Device bans
Keys ▸           Keys (every voucher, searchable) · Key batches (export / cancel unused)
Products         product + its plans (inline) + per-product settings
Settings ▸       Store & reseller connections · Connect your app · Activity log · Team access
```

That is 5 primary items instead of 12. Pages that disappear: Product groups (deleted), Plans (inline in Products), HWID reset history (activity log), Bans (license suspend), Service down and Added time (product action), and Change log (renamed Activity log, under Settings).

The most common support request in this market is "here's my key, it doesn't work", pasted in Discord. So **search by key works everywhere**: the dashboard search, the Keys list and the Licenses list (which matches licenses through the keys redeemed into them). The key row always links to the customer's license.

### 5.3 Screen-by-screen recommendations

**Dashboard (new) — P0/P1**
- Stat cards: Active licenses, Unused keys, Keys redeemed (7 days), Expiring in 7 days.
- Primary buttons: **Generate keys** and **Find customer or key** (search by key, email/username or device).
- A setup checklist for new installs: Create a product → Add a plan → Generate your first keys → Connect your app → (optional) Add a store connection. Each step links to the right place and ticks itself off.

**Licenses — P0**
- Columns: **Customer**, **Product**, **Status** badge (Active / Expiring soon / Expired / Free access / Paused (18 days left) / Suspended / Cancelled), **Expires** (relative, e.g. "in 12 days", with the exact time on hover in local time; "Lifetime"), **Device** (Locked / Not locked yet), **Keys redeemed** (count).
- Global search over customer and redeemed keys. Filters: Product, Status.
- Row actions in one "⋯" menu with plain verbs: **Add time**, **Pause / Resume** (with optional "Pause until"), **Suspend / Unsuspend**, **Reset device**, **Cancel license**, **Edit note**.
- Bulk actions: Add time, Suspend.
- **Ban device** is in the row menu and on the detail page (F4a). It warns when other accounts share that PC.
- Header action: **Give license**. Pick customer, product and plan (or a custom number of days). It creates a one-key admin batch and redeems it in one step, replacing today's four-step path (U2).
- A **license detail page** (infolist): status, expiry, device (fingerprint shortened, with a "Reset device" button), the keys redeemed into it (with batch and source), active loader sessions, note, and a **timeline** from the activity log (key redeemed, device locked, reset, time added, suspended).

**Keys — P0**
- Columns: **Key** (copy button), **Product · Plan**, **Status** (Unused / Redeemed / Cancelled), **Redeemed by** (links to that license), **Redeemed at**, **Batch**.
- Search by key (with or without dashes), note and customer. Filters: Product, Status, Batch.
- Row actions: **Redeem for customer**, **Cancel key** (unused only), **Edit note**.
- Header action: **Generate keys**.

**Generate keys (wizard or modal) — P0**
1. Product (preselected if launched from a product). 2. Plan, shown as "30 days", "Lifetime", etc. 3. How many. 4. Optional label ("For reseller Bob", "October giveaway") and optional store connection.
- On submit, show a result panel with the keys, **Copy all** and **Download .txt**. Don't auto-download in the background.
- The idempotency reference is generated invisibly. It matters only for the API.

**Products — P0**
- Form: Name (slug auto-generated, under "Advanced"), **Lock each license to one device** (toggle with a one-line explanation), **Customers can move to a new PC** (Never / every 1, 7, 14 or 30 days; shown only when the lock is on), **Status** (Active / Maintenance — "the software won't start" / Discontinued — "no new keys"), **Free weekend** (optional "from" and "until" date and time; the product list shows a "Free weekend live" badge while it runs).
- **Plans inline** (repeater or relation manager): Name, Duration as a dropdown (1 day, 7 days, 30 days, 90 days, 1 year, Lifetime, Custom…), Active.
- Product actions: **Add time to active licenses** (the F12 replacement, with a live "This will add 6 hours to 1,284 licenses" preview), **Generate keys**, **Connect your app**.

**Key batches — P1**
- Columns: Batch (label, or "Batch #12"), Product · Plan, Created by / connection, Created, and Unused / Redeemed / Cancelled counts as a small segmented bar.
- Actions: **View keys** (opens Keys filtered to the batch), **Download unused keys**, **Cancel unused keys**. The confirmation says "Customers who already redeemed keep their time". The note is optional.

**Settings ▸ Store & reseller connections — P1**
- Form: Name ("Shopify store", "Reseller: Bob"), Products (all, or pick), Max keys per request, Active.
- After save, show the token **once** in a modal with a copy button and a "store it now" warning. A **Regenerate token** action replaces it. There are no scopes, owner select, rotation expiry or retirement.
- Show the API endpoint and a curl example right there.

**Settings ▸ Connect your app — P0**
- Shows the login, heartbeat and redeem URLs for each product, the response-signing public key, and copy-paste loader snippets (C++, C#, Python, curl), with the error vocabulary explained in plain words.
- States whether the default loader API is on, and links to the "write your own" guide for sellers who turned it off.
- Includes a **"Check a customer"** box: pick a customer, a product and (optionally) a device string, and see exactly what the loader would be told ("Allowed until 3 Nov", "Device mismatch", "Suspended"). It never asks for the customer's password. Sellers can debug support tickets without writing code.

**Settings ▸ Activity log — P1**
- A human sentence per row: "Alex reset the device on Q7M4K-… (Customer replaced PC)". Filter by license, product and person. JSON appears only in an expandable "details" section.

**Settings ▸ Team access — P2**
- Explains the two roles (Admin, Support) and how to assign them. Link to the Shield setup only for those who use it.

### 5.4 Cross-cutting UI rules

| Rule | Why |
|---|---|
| A glossary is enforced in code (see appendix B): no "entitlement", "issuer", "HWID", "scope" or "batch" in seller-facing text except "Key batch". | U3 |
| Reasons and notes are optional everywhere. The activity log already captures who and when. | U4 |
| Show times in the app/panel timezone with relative formatting. Store UTC. | U5 |
| Friendly error messages come from a `lang/en/licensekit.php` map, e.g. `key_already_claimed` → "This key has already been used." | U3 |
| Every destructive confirmation says what *won't* change ("Customers who already redeemed keep their time"). | Trust |
| Empty states have a call to action ("No products yet — Create your first product"). | U6 |
| Navigation has icons, a fixed sort, and secondary items under "Settings". | U1 |
| A misconfigured install shows a "Setup needed" page listing the problems. | U7 |

---

## 6. Documentation

**Finding:** 591 public lines, about 1,490 internal lines, and a 1,561-line OpenAPI file. Governance rules decide which document "owns" which sentence. §3 S4 lists the contradictions found before the internal set was deleted. Sentences like *"Paid expiry, revocation, and entitlement-specific blocks disqualify that paid grant but do not independently deny a host-approved free grant"* are precise but unreadable for the target audience.

**Target (P1, M):**

| File | Audience | Content | Size guide |
|---|---|---|---|
| `README.md` | everyone | what it is, a 60-second quick start, screenshots | ≤150 lines |
| `docs/getting-started.md` | seller | install, first product, first keys, connect app | ≤150 |
| `docs/client-api.md` | app developer | loader login/heartbeat/redeem, errors, signing, snippets, and a copyable controller for sellers who write their own. Generate OpenAPI from routes (e.g. Scramble) instead of hand-maintaining 1,561 lines. | ≤150 |
| `docs/selling-keys.md` | seller | batches, resellers, store connections, refunds | ≤120 |
| `docs/extending.md` | Laravel dev | PHP services, device matcher, events | ≤120 |
| `docs/decisions.md` | maintainer | short ADR-style log: decision, reason, date. No precedence rules. | ≤200 |

The internal set, with its governance rules, reference protocols and Windows HWID research, is already deleted (D9). The remaining public docs (`client.md`, `shops.md`, `importing.md`, `installation.md`) should be rewritten into the files above. Keep `decisions.md` in git as the short, durable record of decisions D1–D9.

---

## 7. Tests, CI, dependencies and tooling

**T1. Tests mirror the complexity.** There are 9,336 lines. Fixtures implement a fake host desktop protocol (`ClientSession`, `ClientSessions`, `ClientSecurity`, `HostDesktopController`, `ClientProtocolProvider`, …), a test of code the package explicitly does not ship. Permission tests cover the 58-ability matrix.
→ Tests shrink naturally as features go. Delete the desktop-protocol fixtures. Keep focused tests for redemption, loader login and heartbeat, generation and idempotency, device lock and reset, add-time, and API auth. Keep **two** MySQL race tests (concurrent redemption of the same key, and concurrent identical issuance). P1.

**T2. CI matrix.** It has 9 combinations (PHP 8.3/8.5 × Laravel 12/13 × Filament 4/5 × minimum Sanctum pins), 2 lowest-dependency consumer jobs and a MySQL job.
→ **Decided (D8): keep PHP 8.3+, Laravel 12 and 13, and Filament 4.13.3+ and 5.8.3+.** Once Sanctum is removed (F19), the jobs that exist only to pin minimum Sanctum versions can go. Keep the Filament 4/5 × Laravel 12/13 coverage, the lowest-dependency jobs and the MySQL job. P2.

**T3. Dependencies.**
- Drop `laravel/sanctum` (F19).
- The individual `illuminate/*` requirements are redundant next to `filament/filament`, which requires the framework. Keep only what the package directly needs, or just `filament/filament`.
P1.

**T4. Static analysis and style** (Larastan, Pint) is good. Add a line-length rule (§4.8). P2.

---

## 8. What to keep

These decisions are sound. Keep them through the simplification:

- **No payments, storefront or orders in core.** The commerce boundary is right.
- **Idempotent batch creation by reference** for API callers, and safe retries.
- **The serial format**: 25 characters, unambiguous alphabet, and generation backed by a unique index.
- **Atomic key redemption**, with the activity log written in the same transaction.
- **A replaceable device matcher**, reduced to one method.
- **One Composer package** with no internal package split.
- **Filament as the admin**, with standard resources and the panel plugin.
- **An activity log** that records who did what.
- **`licensekit:check`** as the single place for diagnostics.
- **MySQL race testing** for the two operations that need it.

---

## 9. Prioritized roadmap

Sequenced so that each phase deletes code before the next one adds UI.

### Phase 0 — Decide (days)
1. ~~Activation model~~: **decided, account-first** (D1).
2. ~~Where keys are redeemed~~: **decided, website or loader after login** (D2).
3. ~~Client API~~: **decided, simple default loader API that can be turned off** (D3).
4. ~~Support matrix~~: **decided, keep as is** (D8).
5. Freeze new features until Phase 2 ships.

### Phase 1 — Cut (P0, about 1–2 weeks)
| Step | Section | Effort |
|---|---|---|
| Delete imports, `KeyNormalizer`/`CanonicalKeys`, `SerialKey::normalize` | F9, F10 | S |
| Delete product groups | F3 | S |
| Keep pause/resume with the D10 rules (banked redemptions, "Pause until", status badge) | F6 | S |
| Replace the outages module with "Add time to active licenses" plus product maintenance status; delete the module system | F12, F1 | M |
| Collapse bans, revoke and block into license status; collapse product block, disable and outage into product status | F1 | M |
| Delete reseller ownership, retirement and `ResellerInventory`; an API connection is the reseller | F13 | S/M |
| Collapse authorization to two gates and Filament-native policies; services stop authorizing | F15 | M |
| Device: per-product only, on the license row, string fingerprint, one-method matcher, no upgrade, no encryption mode, reset history into the log | F4 | M |
| Rename Entitlement to License; rewrite the single initial migration to the target schema (allowed: no users) | F7 | M |

### Phase 2 — Rebuild the seller experience (P0/P1, about 2–3 weeks)
| Step | Section | Effort |
|---|---|---|
| Default loader API (`login`, `heartbeat`, `redeem`, `move-device`) with `loader_sessions`, signed responses, and an off switch | F23 | M |
| "My licenses" Livewire component (redeem + reset device), optional JSON routes | F23a | M |
| Device bans: table, "Ban device" action with shared-PC warning, Device bans list, loader enforcement | F4a | M |
| Scheduled free weekend (`free_from`/`free_until`) in the product form | F5 | S |
| One issuance service; delete `IssueLicenses`/`ManagementOperations`; fix the double `run` | F8, F22 | M |
| Replace Sanctum with hashed connection tokens; simplify permissions | F19, F20 | S |
| New admin IA: Dashboard, Licenses (with detail page), Keys and Key batches, Products (inline plans), Settings | §5 | L |
| Generate-keys modal, Give-license action, Connect-your-app page with a "Check a customer" box | §5.3 | M |
| Glossary, friendly errors, optional notes, local time | §5.4 | S |

### Phase 3 — Harden and tidy (P1/P2)
| Step | Section | Effort |
|---|---|---|
| Remove runtime readiness and `Schema::hasTable`; add a "Setup needed" page | F17 | S |
| Simplify locking; keep two MySQL race tests | F16, T1 | M |
| Single activity-log API; two events | F24, F25 | S |
| Flatten config | §4.9 | S |
| Docs rewrite (6 files) | §6 | M |
| Drop Sanctum-only CI jobs; trim dependencies | T2, T3 | S |
| Line-length rule, error-code enum, small dead code | §4.8 | S |

---

## 10. Open decisions for the owner

1. ~~Key-first or account-first?~~ Decided: account-first (D1).
2. ~~Is the target user someone who writes PHP?~~ Decided: no by default. Ship a simple loader API that can be replaced (D3).
3. ~~Hardware bans~~: Decided: built-in device bans (D6).
4. ~~Free access~~: Decided: scheduled per-product free weekend (D7).
5. ~~Filament 4 support~~: Decided: keep the current matrix (D8).
6. ~~Internal docs in git?~~ Decided: deleted (D9).
7. ~~Self-service device reset~~: Decided: loader *and* website, with a per-product cooldown; support always overrides (D4).
8. ~~Email verification~~: Decided: no LicenseKit setting; follow the host's `MustVerifyEmail` (D5).

---

## Appendix A — Feature disposition table

| Feature | Disposition | Replacement |
|---|---|---|
| Products, plans | Keep (plans inline in product UI) | — |
| Key generation / batches | Keep, simplified attribution | — |
| Claim into account | Keep; reachable from website and loader | — |
| Entitlement | Keep, rename to License; device lock folded into it | — |
| Device bindings table | Merge | License row columns |
| Revoke / block | Merge | License status |
| Licensing bans | Remove | Suspend license; host user suspension |
| Pause / resume | Keep, friendlier rules (D10) | `paused` status + banked time + optional auto-resume |
| Product groups | Remove | Optional `category` text later |
| Product enable/block | Merge | Product status |
| Outages + compensation + manual credits | Remove | "Add time to active licenses" + maintenance status |
| HWID global scope | Remove | Per-product lock |
| HWID encrypted storage mode | Remove | Plain fingerprint |
| HWID upgrade / `canUpgrade` | Remove | Reset |
| Hardware bans via host `HwidHandler::assertAllowed` | Replace | Built-in device bans (D6, F4a) |
| `requires_hwid` per product | Keep | "Lock to one device" toggle |
| `bypassHwid` gate | Remove | (Support role, if ever needed) |
| Reset history table | Merge | Activity log |
| Customer reset (enabled / cooldown / require reason config) | Simplify | Per-product "move to a new PC" cooldown; loader move + website reset |
| `user.require_verified` setting | Remove | Follow host `MustVerifyEmail` (D5) |
| Customer redeem/reset pages (host-built) | **Add** | `<livewire:licensekit.my-licenses />`, optional JSON routes |
| Host free access (`freeUntil`) | Simplify | Scheduled per-product free weekend (D7) |
| `AccessRestriction` | Remove | Host wraps calls / events |
| Imports + `KeyNormalizer` | Remove | One-off command if ever needed |
| Local integrations config | Remove | Call issuance service directly |
| API clients + Sanctum + 3 scopes | Simplify | API connections with hashed token |
| Reseller ownership / retire / `ResellerInventory` | Remove | Connection = reseller |
| Management API (6 endpoints) | Keep, simplify config | — |
| Loader API | **Add** (can be turned off) | `login` / `heartbeat` / `redeem` + `loader_sessions` + signed responses |
| Per-model policy matrix + Shield recipe | Simplify | Admin / Support gates; Filament-native policies |
| Audit events + config observer | Merge | Activity log |
| 4 events + provenance DTOs | Simplify | `KeyRedeemed`, `AccessRevoked` |
| Runtime readiness checks | Remove | `licensekit:check` + Setup page |
| Module framework | Remove | — |

## Appendix B — Seller-facing glossary

| Internal / current | Show sellers |
|---|---|
| Entitlement | License |
| Claim | Redeem key |
| LicenseKey (unclaimed) | Unused key |
| Batch | Key batch |
| Issuer / reference | *(hidden)* |
| HWID / device binding / scope | Device / device lock |
| Reset HWID | Reset device |
| Revoke | Cancel license |
| Block / ban | Suspend |
| Pause | Pause (shown as "Paused, 18 days left") |
| Manual maintenance block / service down | Maintenance mode |
| Compensation / added time | Add time |
| API client / management token | Store connection / connection token |
| Audit events / change log | Activity log |

## Appendix C — Defects and inconsistencies found in passing

| # | Location | Issue |
|---|---|---|
| 1 | `src/Core/Http/Controllers/Management/BatchController.php:20` | `store()` calls `ManagementAccess::run`, and `ManagementOperations::create` calls `run` again: double auth, nested transaction, two client locks. |
| 2 | `docs/internal/management-api.md` (giveaway example) *(deleted, D9)* | The curl body includes `"method":"on_demand"`, which `ManagementWriteRequest` rejects (`'method' => ['missing']`). No action needed. |
| 3 | `src/Support/OutageState.php` | `Schema::hasTable()` runs on every access check, claim and clock change. |
| 4 | `src/Http/Middleware/EnsureConfigured.php`, `AccessPolicy::check` | Full config validation (including user provider construction and encrypter resolution) runs per request and per access check. |
| 5 | `src/LicenseKitPlugin.php:43` | Invalid config silently registers no resources. |
| 6 | All mutating services | `Product::lockForUpdate()` serializes every claim and batch per product. |
| 7 | `src/Core/Http/Resources/EntitlementResource.php` | Unused class. |
| 8 | `src/Core/Actions/ClaimLicense.php:91-99` | `ClaimProvenance` is constructed twice with mostly identical arguments. |
| 9 | `docs/internal/*.md` *(deleted, D9)* | 17 references to the non-existent `apps/starter` and `todo/old/*`. No action needed. |
| 10 | `docs/internal/filament-pivot.md` ability table *(deleted, D9)* | Missing `extend`, `pause`, `resume`, `retire` and `bypassHwid`, which `docs/installation.md` lists. No action needed. |
| 11 | `docs/internal/development.md` *(deleted, D9)* | Support versions contradict the README and CI. No action needed. |
| 12 | `docs/internal/licensing.md` *(deleted, D9)* | "No import operation" contradicts `docs/importing.md`. No action needed. |
| 13 | `docs/internal/feature-priorities.md` *(deleted, D9)* | Lists pause and imports as deferred; both are implemented. No action needed. |
| 14 | `README.md` | Promises a "Turnkey dashboard"; none exists. |
| 15 | `.gitattributes` | Export-ignores paths that don't exist (`/apps`, `/packages`, `browser-tests.yml`). |
| 16 | `src/Core/Actions/ManageEntitlements.php` | The actor is re-queried and locked twice per operation (in the transaction body and again in `authorize`). |
