# Existing keys and historical imports

These APIs were introduced in `0.1.0-alpha.2`. LicenseKit remains one MIT package requiring Filament 4/5. Jobs and commands can call licensing APIs without a panel session. Host applications own their source database readers and commerce integration.

## Custom key formats

Configure `licensekit.key_normalizer` with a class implementing `LicenseKit\Core\Contracts\KeyNormalizer`. Default `StandardKeyNormalizer` retains the standard 25-character key format. A host can recognize its own historical syntax and delegate standard keys:

```php
use LicenseKit\Core\Actions\StandardKeyNormalizer;
use LicenseKit\Core\Contracts\KeyNormalizer;
use LicenseKit\Core\Exceptions\LicensingException;

final class ExistingKeyNormalizer implements KeyNormalizer
{
    public function __construct(private StandardKeyNormalizer $standard) {}

    public function normalize(#[\SensitiveParameter] string $value): string
    {
        $value = trim($value);
        if (str_starts_with($value, 'LEGACY/')) {
            if (preg_match('/\ALEGACY\/[A-Za-z0-9-]{8,64}\z/D', $value) !== 1) {
                throw new LicensingException('invalid_key', 422);
            }

            return $value;
        }

        return $this->standard->normalize($value);
    }
}
```

Set `'key_normalizer' => ExistingKeyNormalizer::class` in host configuration, or bind the interface in a host provider after package registration. Parsing rules belong to the host. Do not look up orders or grant access in a normalizer.

Input and canonical output are limited to 255 bytes. Canonical output must be nonempty printable ASCII without leading/trailing whitespace. Internal spaces and punctuation can be significant. Output must be deterministic and idempotent. Database uniqueness and lookup use exact, case-sensitive canonical values; MySQL uses `ascii_bin`, SQLite uses its default binary equality. Supported/tested database targets for these integrations are SQLite and MySQL 8.4.

Generation remains standard. A custom normalizer must accept generated standard keys unchanged, or issuance rolls back. Invalid canonical output produces `invalid_key_normalizer`, status 503. Invalid customer syntax should produce `invalid_key`, status 422. Claims keep all existing authorization, bans, restrictions, outage, expiry, and locking checks regardless of format.

Scan all source keys through the selected normalizer before import and reject collisions. A per-record preflight cannot detect collisions among other unpersisted source rows. Keep customer activation inputs usable after storage normalization. Never silently remove meaningful prefixes or try multiple database matches. Do not change normalizers later without a compatibility/data migration.

## Trusted import APIs

Imports are disabled by default. Temporarily set `licensekit.imports.enabled` to `true` for a controlled host command. No HTTP import endpoint is provided. This switch is operational discipline for trusted PHP code, not a sandbox against code already executing in the application.

`ImportLicensing` exposes:

| Method | Typed payload | Meaning |
| --- | --- | --- |
| `entitlement()` | `EntitlementSnapshot` | Exact effective state for a user/product |
| `key()` | `KeySnapshot` | Unclaimed or historical claimed/revoked key |
| `device()` | `DeviceSnapshot` | Existing binding and last-reset time |
| `reset()` | `ResetSnapshot` | Historical reset audit entry |
| `preflight()` | Any snapshot above | Safe per-record validation report without writes |

All write methods accept `ImportSource`, a typed snapshot, and optional `dryRun: true`. They return `ImportResult` with `recordType`, nullable `targetId`, `replayed`, and `dryRun`. Preflight returns `source_namespace`, `source_reference`, `record_type`, `valid`, and nullable `error`. Validation failures are returned as domain error codes without serials or hardware payloads. Infrastructure failures propagate; do not treat an unavailable database as a valid report.

```php
use Carbon\CarbonImmutable;
use LicenseKit\Core\Actions\ImportLicensing;
use LicenseKit\Core\Data\EntitlementSnapshot;
use LicenseKit\Core\Data\ImportSource;
use LicenseKit\Core\Data\KeySnapshot;

$imports = app(ImportLicensing::class);
$source = new ImportSource('previous-system', 'access:123', 'migration:initial');
$state = new EntitlementSnapshot(
    userId: $mappedUserId,
    productId: $mappedProductId,
    expiresAt: CarbonImmutable::parse('2030-01-01T00:00:00Z'),
);
$report = $imports->preflight($source, $state);
if (! $report['valid']) {
    // Stop and reconcile the source record using its reference and error code.
    throw new RuntimeException($report['error']);
}
$access = $imports->entitlement($source, $state);

$imports->key(
    new ImportSource('previous-system', 'key:456', 'migration:initial'),
    new KeySnapshot(
        key: 'LEGACY/Abcd-1234',
        planId: $mappedPlanId,
        durationDays: 30,
        entitlementId: $access->targetId,
        claimedAt: CarbonImmutable::parse('2029-12-02T00:00:00Z'),
    ),
);
```

This key example requires the custom normalizer above. An unclaimed key has neither `claimedAt` nor `entitlementId`. Claimed history requires both. Its entitlement product must match its plan. Duration is the key's original snapshot, can differ from today's plan, and must be 1–3650 days or null for lifetime. Disabled historical plans/products can be imported. Ordinary issuance still checks enabled state.

Import effective entitlement state directly. Never sum historical purchase durations or invoke claims to reconstruct it. `EntitlementSnapshot` accepts nullable `userId` for deleted-user history, `isLifetime`, `isRevoked`, `isBlocked`, `expiresAt`, `pausedAt`, and `remainingSeconds`. Lifetime cannot also have expiry or pause state. Paused state requires a nonnegative balance and no running expiry; an unpaused record cannot hold a pause balance. Null expiry by itself does not mean lifetime. Existing user/product entitlements cannot be replaced by another source record.

Import entitlements before claimed keys. Keys receive deterministic historical batches grouped by source namespace, plan, and duration. Batch quantities count imported keys, with no claim clock or sale event. Import the same source record twice and it returns its original target ID without changing current state. Reusing a source identity with different snapshot content returns `import_source_conflict`, status 409. Another source colliding with an existing key, entitlement, or binding returns `import_target_conflict`, status 409. Source identity includes namespace, record type, and reference, and is case-sensitive. Use nonsecret source row IDs as references, never serials or hardware fingerprints. Namespace/reference are printable ASCII, nonempty, trimmed, and at most 128 bytes; audit actor is nonempty and at most 255 bytes.

Source mappings retain a keyed fingerprint, not raw source payloads. Fingerprint key defaults to `app.key`; set `licensekit.imports.fingerprint_key` to a stable secret before the first import if application key rotation is expected. Preserve that secret for retries. Changing it makes identical payloads conflict. Mapping retention is intentional even if host maintenance later removes a target; a retry never recreates historical access automatically.

`DeviceSnapshot` takes `userId`, a product ID to establish scope, nullable canonical `hwid`, `boundAt`, and `lastResetAt`. Set global/per-product configuration before importing. Binding time and HWID must both be present or both absent. HWID must already be normalized by the configured handler; import rejects payloads that would change. Preserve reset time even for an empty binding to retain cooldown state. `ResetSnapshot` takes user ID, nullable product ID for global history, reset time, `performedBy` of `admin` or `customer`, actor ID, optional reason, and optional previous HWID. Deleted historical actor/user IDs are allowed in reset history. Reset history alone does not update a binding's cooldown; import its latest reset timestamp with the binding too.

Use explicit source timezone when creating `CarbonImmutable` inputs. Package persists UTC with second precision. Key claim/revocation timestamps and binding/reset timestamps read and write in UTC. When mapping an external source, translate its local timestamps to UTC explicitly. Do not infer an unknown timezone or change the original instant.

Imports write licensing audit records, not ordinary claim, session-invalidation, compensation, or delivery events. Imports join outer transactions. A write pause and final-delta procedure are required before authoritative cutover; row locks are not a complete migration coordination protocol. Preflight never reserves records and must be followed by validation again during persistence. Disable imports after completion.

Keep keys and HWIDs out of command output, logs, exception context, and exported reports. Snapshot/result objects can contain secrets and must not be logged or serialized wholesale.

## Alpha installation and compatibility

LicenseKit has no deployed consumers to preserve. The initial core migration contains the complete schema, including pause state, 255-byte canonical key storage, and import source mappings. No incremental alpha upgrade migration is provided. Fresh installations use `php artisan migrate`. Disposable development installations that already ran an older schema must rebuild their test database; ordinary `migrate` does not rerun an applied initial migration.

Rebuild configuration caches after updating so new defaults are available. Existing supported admin/management operations remain available. `SerialKey::normalize()` is a deprecated compatibility wrapper; resolve `KeyNormalizer` or use normal claim/import operations instead.

No payment integrations, host models, or application-specific key formats are shipped. Automated reversal of claimed renewals remains a separate design; revoking an entire entitlement can erase unrelated access. Preserve purchase-to-batch mappings in the host and use an explicit support workflow meanwhile.
