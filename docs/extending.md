# Extending LicenseKit

Services: `LicenseKit\Core\Actions`. Models: `LicenseKit\Core\Models`. Authorize in controller/Filament action; actor parameters only attribute activity.

## Stable PHP API

Build your own app protocol on these. From 1.0 they keep their signatures within a major version; until then, breaking changes are marked **Breaking** in the changelog.

- `LicensedDevices`: `bind`, `verify`, `moveDevice`, `reset`, `adminReset`, `eligibility`
- `AccessPolicy::authorize`, `SignInCheck::check`, `ClaimLicense::claim`, `Keys::issue`
- `ManageLicenses`, `ManageKeys`, `DeviceBans`, `CustomerLicenses`, `ResellerBatches`, `SignInProblems::record`
- Events `KeyRedeemed`, `AccessRevoked`; `LicensingException` `error` and `status`

Every check (account, license, PC lock, bans, expiry) runs inside these calls, so a custom protocol cannot skip one. Refusals from `LicensedDevices` are logged for the license page's "Why can't they sign in?" (same problem once per 10 minutes); if you call them inside your own transaction, log rolled-back refusals yourself with `SignInProblems::record` after it ends. Your protocol picks its routes, fields, tokens and signing. Full example: [CustomProtocolTest](../tests/Core/Feature/CustomProtocolTest.php) and [its controller](../tests/Core/Fixtures/CustomProtocolController.php).

## Issue and redeem

```php
use LicenseKit\Core\Actions\Keys;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Data\IssueContext;

$batch = app(Keys::class)->issue($plan, 10, new IssueContext(
    source: 'app', reference: 'order:123', actor: $staff, label: 'October sale',
));
$license = app(ClaimLicense::class)->claim($customer, $batch->keys[0]->key);
```

Plans are `LicensePlan`. `duration_days=null` means lifetime. Batch stores duration snapshot. Context accepts source, reference, actor, connection, label, optional customDays and reseller. Sources: admin/api/app. Omitted reference is generated.

## Reseller ownership

`IssueContext::reseller` accepts a host user model. If omitted, issuance uses the connection's `user_id`. `Batch::reseller()` and `ApiConnection::user()` resolve the configured host user model. The nullable foreign keys are `licensekit_batches.reseller_id` and `licensekit_api_connections.user_id`; deleting the user sets them to null and turns that user's connections off, so no token is left working without an owner. `created_by` still records who generated the batch, independently of who bought it.

`ResellerBatches::paginate($user, $perPage = 25)` returns batches with `total_count`, `redeemed_count`, `unused_count` and `cancelled_count`, plus their plan and product. Page size must be between 1 and 100. `unusedKeys($user, $batch)` exports unused serials and returns 404 for a batch assigned to someone else. See [selling keys](selling-keys.md) for host PHP and Livewire examples.

## Access and devices

- `AccessPolicy::authorize($user, $product)` checks without writes; returns `AccessAuthorization` or throws `LicensingException`.
- `LicensedDevices::bind($user, $product, $device)` checks access and binds first PC.
- `LicensedDevices::verify(...)` checks existing lock without writes.
- `LicensedDevices::reset($user, $product)` clears lock with customer cooldown.
- `LicensedDevices::moveDevice($user, $product, $device)` replaces lock and returns License. Movement alone does not grant software access.
- `LicensedDevices::adminReset($user, $product, $actor)` bypasses cooldown.
- `CustomerLicenses::paginate($user)`, `find($user, $licenseId)` return safe summaries.

Fingerprints are UTF-8 strings up to 1024 bytes, stored plainly but hidden from model serialization. Replace `device_matcher` with implementation of:

```php
public function matches(Product $product, string $bound, string $presented): bool;
```

Bans match exact presented string by hash independently of fuzzy matcher. Spoofed fingerprints can bypass bans.

## Changes and events

`ManageLicenses` handles time, pause/resume, suspend/unsuspend, cancel/restore and notes. `ManageKeys` handles unused-key/batch cancellation, export and notes. Reasons optional.

`KeyRedeemed($keyId, $licenseId)` fires after commit. Use for notifications (thank-you mail, Discord post); it is not durable delivery. Rollback dispatches nothing. `AccessRevoked($license)` fires synchronously so default sessions delete in same transaction. Custom protocols should listen and recheck host account state each heartbeat.

```php
use Illuminate\Support\Facades\Event;
use LicenseKit\Core\Events\KeyRedeemed;
use LicenseKit\Core\Models\LicenseKey;

Event::listen(function (KeyRedeemed $event): void {
    $key = LicenseKey::with('license.user', 'batch.plan.product')->find($event->keyId);
    Mail::to($key->license->user)->send(new ThanksForBuying($key->batch->plan->product->name));
});
```

Call `ActivityLog::record($actor, $event, $subject, $meta)` in your transaction. Actor can be `user:123` or application label. Direct Eloquent catalog writes no longer have implicit audit observer; log explicitly.

## Configuration

Flat API keys: `client_enabled`, `client_prefix`, `store_api_enabled`, `store_api_prefix`, `portal_api_enabled`, `portal_api_prefix`. Other options: `client_login_column`, `device_matcher`, `max_batch_size`, `user_label`, `user_guard`, `user_suspended_column`, `signing_key_path`.

Null user_guard follows host default. Null user_suspended_column adds no suspension check. Runtime settings such as free access and device rules live on products.

Diagnostics run in `licensekit:check` and panel setup flow. Core services perform no schema checks or full config validation per call. Rebuild route/config caches after changing API registration.
