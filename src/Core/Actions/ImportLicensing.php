<?php

namespace LicenseKit\Core\Actions;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LicenseKit\Core\Casts\HwidPayload;
use LicenseKit\Core\Contracts\ReadinessCheck;
use LicenseKit\Core\Data\DeviceSnapshot;
use LicenseKit\Core\Data\EntitlementSnapshot;
use LicenseKit\Core\Data\ImportResult;
use LicenseKit\Core\Data\ImportSource;
use LicenseKit\Core\Data\KeySnapshot;
use LicenseKit\Core\Data\ResetSnapshot;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\DeviceBinding;
use LicenseKit\Core\Models\DeviceResetHistory;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Core\Users\ConfiguredUserModel;

/** Trusted, explicitly enabled maintenance operations. No redemption or delivery side effects. */
class ImportLicensing
{
    public function __construct(private CanonicalKeys $keys, private Audit $audit, private ReadinessCheck $readiness) {}

    /**
     * Validate one record without persistent writes. Hosts aggregate reports and scan source-wide collisions.
     *
     * @return array{source_namespace: string, source_reference: string, record_type: string, valid: bool, error: string|null}
     */
    public function preflight(ImportSource $source, #[\SensitiveParameter] EntitlementSnapshot|KeySnapshot|DeviceSnapshot|ResetSnapshot $snapshot): array
    {
        $type = match (true) {
            $snapshot instanceof EntitlementSnapshot => 'entitlement',
            $snapshot instanceof KeySnapshot => 'key',
            $snapshot instanceof DeviceSnapshot => 'device',
            $snapshot instanceof ResetSnapshot => 'reset',
        };
        $error = null;
        try {
            $this->{$type}($source, $snapshot, dryRun: true);
        } catch (LicensingException $exception) {
            $error = $exception->error;
        } catch (ValidationException|\JsonException) {
            $error = 'invalid_import_state';
        }

        return ['source_namespace' => $source->namespace, 'source_reference' => $source->reference,
            'record_type' => $type, 'valid' => $error === null, 'error' => $error];
    }

    public function entitlement(ImportSource $source, EntitlementSnapshot $snapshot, bool $dryRun = false): ImportResult
    {
        return $this->run($source, 'entitlement', $snapshot, $dryRun, function (bool $write) use ($snapshot): ?Model {
            $this->lockOwner($snapshot->userId);
            $this->product($snapshot->productId);
            if (($snapshot->isLifetime && ($snapshot->expiresAt !== null || $snapshot->pausedAt !== null || $snapshot->remainingSeconds !== null))
                || ($snapshot->pausedAt !== null && ($snapshot->expiresAt !== null || $snapshot->remainingSeconds === null))
                || ($snapshot->pausedAt === null && $snapshot->remainingSeconds !== null)
                || ($snapshot->remainingSeconds !== null && $snapshot->remainingSeconds < 0)) {
                throw new LicensingException('invalid_import_state', 422);
            }
            if ($snapshot->userId !== null && Entitlement::where('user_id', $snapshot->userId)->where('product_id', $snapshot->productId)->lockForUpdate()->exists()) {
                throw new LicensingException('import_target_conflict', 409);
            }
            if (! $write) {
                return null;
            }

            return Entitlement::create(['user_id' => $snapshot->userId, 'product_id' => $snapshot->productId,
                'is_lifetime' => $snapshot->isLifetime, 'is_revoked' => $snapshot->isRevoked, 'is_blocked' => $snapshot->isBlocked,
                'expires_at' => $this->date($snapshot->expiresAt), 'paused_at' => $this->date($snapshot->pausedAt), 'remaining_seconds' => $snapshot->remainingSeconds]);
        });
    }

    public function key(ImportSource $source, #[\SensitiveParameter] KeySnapshot $snapshot, bool $dryRun = false): ImportResult
    {
        return $this->run($source, 'key', $snapshot, $dryRun, function (bool $write) use ($source, $snapshot): ?Model {
            $canonical = $this->keys->normalize($snapshot->key);
            LicenseDuration::days($snapshot->durationDays);
            $entitlement = $snapshot->entitlementId === null ? null : Entitlement::find($snapshot->entitlementId);
            if ($snapshot->entitlementId !== null && $entitlement === null) {
                throw new LicensingException('invalid_import_state', 422);
            }
            $this->lockOwner($entitlement?->user_id);
            $plan = LicensePlan::find($snapshot->planId) ?? throw new LicensingException('plan_unavailable');
            $this->product($plan->product_id);
            $plan = LicensePlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if (($snapshot->claimedAt === null) !== ($snapshot->entitlementId === null)
                || ($entitlement !== null && $entitlement->product_id !== $plan->product_id)) {
                throw new LicensingException('invalid_import_state', 422);
            }
            if (LicenseKey::where('key', $canonical)->lockForUpdate()->exists()) {
                throw new LicensingException('import_target_conflict', 409);
            }
            if (! $write) {
                return null;
            }
            $issuer = 'import:'.hash('sha256', $source->namespace);
            $reference = 'plan:'.$plan->id.':days:'.($snapshot->durationDays ?? 'lifetime');
            $batch = Batch::where('issuer', $issuer)->where('reference', $reference)->lockForUpdate()->first();
            $batch ??= Batch::create(['issuer' => $issuer, 'reference' => $reference, 'license_plan_id' => $plan->id,
                'duration_days' => $snapshot->durationDays, 'quantity' => 0, 'label' => 'Historical import']);
            $key = LicenseKey::create(['license_plan_id' => $plan->id, 'batch_id' => $batch->id, 'key' => $canonical,
                'duration_days' => $snapshot->durationDays, 'entitlement_id' => $snapshot->entitlementId,
                'claimed_at' => $this->date($snapshot->claimedAt), 'revoked_at' => $this->date($snapshot->revokedAt)]);
            $batch->increment('quantity');

            return $key;
        });
    }

    public function device(ImportSource $source, #[\SensitiveParameter] DeviceSnapshot $snapshot, bool $dryRun = false): ImportResult
    {
        return $this->run($source, 'device', $snapshot, $dryRun, function (bool $write) use ($snapshot): ?Model {
            $this->lockOwner($snapshot->userId);
            $product = $this->product($snapshot->productId);
            $scope = config('licensekit.device_binding.scope') === 'global' ? 'global' : 'product:'.$product->id;
            if (($snapshot->hwid === null) !== ($snapshot->boundAt === null)) {
                throw new LicensingException('invalid_import_state', 422);
            }
            if ($snapshot->hwid !== null) {
                $this->validateHwid($snapshot->hwid);
                if (app(Hwids::class)->normalize($product, $snapshot->hwid) !== $snapshot->hwid) {
                    throw new LicensingException('invalid_import_state', 422);
                }
            }
            if (DeviceBinding::where('user_id', $snapshot->userId)->where('scope_key', $scope)->lockForUpdate()->exists()) {
                throw new LicensingException('import_target_conflict', 409);
            }
            if (! $write) {
                return null;
            }

            return DeviceBinding::create(['user_id' => $snapshot->userId, 'scope_key' => $scope, 'hwid' => $snapshot->hwid,
                'bound_at' => $this->date($snapshot->boundAt), 'last_hwid_reset_at' => $this->date($snapshot->lastResetAt)]);
        });
    }

    public function reset(ImportSource $source, #[\SensitiveParameter] ResetSnapshot $snapshot, bool $dryRun = false): ImportResult
    {
        return $this->run($source, 'reset', $snapshot, $dryRun, function (bool $write) use ($snapshot): ?Model {
            if ($snapshot->productId !== null) {
                $this->product($snapshot->productId);
            }
            if (! in_array($snapshot->performedBy, ['customer', 'admin'], true)
                || strlen((string) $snapshot->userId) > 64 || (string) $snapshot->userId === ''
                || strlen($snapshot->actorId) > 64 || $snapshot->actorId === '') {
                throw new LicensingException('invalid_import_state', 422);
            }
            if ($snapshot->beforeHwid !== null) {
                $this->validateHwid($snapshot->beforeHwid);
            }
            if (! $write) {
                return null;
            }

            return DeviceResetHistory::create(['user_id' => (string) $snapshot->userId,
                'scope' => $snapshot->productId === null ? 'global' : 'per_product', 'product_id' => $snapshot->productId,
                'reset_at' => $snapshot->resetAt, 'performed_by' => $snapshot->performedBy,
                'actor_id' => $snapshot->actorId, 'reason' => $snapshot->reason, 'before_hwid' => $snapshot->beforeHwid]);
        });
    }

    /** @param Closure(bool): ?Model $operation */
    private function run(ImportSource $source, string $type, object $snapshot, bool $dryRun, Closure $operation): ImportResult
    {
        if (config('licensekit.imports.enabled') !== true) {
            throw new LicensingException('imports_disabled');
        }
        $this->readiness->assertReady();
        foreach ([$source->namespace, $source->reference] as $value) {
            if ($value === '' || strlen($value) > 128 || trim($value) !== $value || preg_match('/\A[\x20-\x7E]+\z/D', $value) !== 1) {
                throw new LicensingException('invalid_import_source', 422);
            }
        }
        if (trim($source->actor) === '' || strlen($source->actor) > 255) {
            throw new LicensingException('invalid_import_source', 422);
        }
        $identity = hash('sha256', json_encode([$source->namespace, $type, $source->reference], JSON_THROW_ON_ERROR));
        $secret = config('licensekit.imports.fingerprint_key') ?? config('app.key');
        if (! is_string($secret) || $secret === '') {
            throw new LicensingException('licensing_unavailable', 503);
        }
        try {
            $fingerprint = hash_hmac('sha256', json_encode($this->canonicalPayload(get_object_vars($snapshot)), JSON_THROW_ON_ERROR), $secret);
        } catch (\JsonException) {
            throw new LicensingException('invalid_import_state', 422);
        }
        $transaction = function () use ($source, $type, $dryRun, $operation, $identity, $fingerprint): ImportResult {
            $existing = DB::table('licensekit_import_records')->where('identity', $identity)->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $fingerprint)) {
                    throw new LicensingException('import_source_conflict', 409);
                }

                return new ImportResult($type, (int) $existing->target_id, true, $dryRun);
            }
            if (! $dryRun) {
                // Reserve source identity before target locks, including under READ COMMITTED isolation.
                DB::table('licensekit_import_records')->insert(['identity' => $identity, 'source_namespace' => $source->namespace,
                    'record_type' => $type, 'source_reference' => $source->reference, 'payload_hash' => $fingerprint,
                    'target_type' => 'pending', 'target_id' => 0, 'actor' => $source->actor,
                    'created_at' => now(), 'updated_at' => now()]);
            }
            $target = $operation(! $dryRun);
            if ($dryRun) {
                return new ImportResult($type, null, false, true);
            }
            if ($target === null) {
                throw new LicensingException('invalid_import_state', 422);
            }
            DB::table('licensekit_import_records')->where('identity', $identity)->update([
                'target_type' => $target::class, 'target_id' => $target->getKey(),
            ]);
            $this->audit->record($source->actor, 'historical_'.$type.'_imported', $target, [],
                ['source_namespace' => $source->namespace, 'source_reference' => $source->reference]);

            return new ImportResult($type, (int) $target->getKey(), false, false);
        };
        try {
            return DB::transaction($transaction, 3);
        } catch (UniqueConstraintViolationException) {
            // A competing import may have won; a retry checks its fingerprint first.
            try {
                return DB::transaction($transaction, 3);
            } catch (UniqueConstraintViolationException) {
                throw new LicensingException('import_target_conflict', 409);
            }
        }
    }

    private function lockOwner(int|string|null $userId): void
    {
        if ($userId !== null && ConfiguredUserModel::className()::whereKey($userId)->lockForUpdate()->first() === null) {
            throw new LicensingException('invalid_import_user', 422);
        }
    }

    private function product(int $productId): Product
    {
        return Product::whereKey($productId)->lockForUpdate()->first() ?? throw new LicensingException('invalid_import_product', 422);
    }

    private function date(?CarbonImmutable $date): ?string
    {
        return $date?->utc()->startOfSecond()->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $payload */
    private function validateHwid(#[\SensitiveParameter] array $payload): void
    {
        try {
            HwidPayload::encode($payload);
        } catch (\InvalidArgumentException|\JsonException) {
            throw new LicensingException('invalid_import_state', 422);
        }
    }

    /** @param array<array-key, mixed> $payload
     * @return array<array-key, mixed> */
    private function canonicalPayload(#[\SensitiveParameter] array $payload): array
    {
        if (! array_is_list($payload)) {
            ksort($payload);
        }
        foreach ($payload as &$value) {
            if ($value instanceof CarbonImmutable) {
                $value = $this->date($value);
            } elseif (is_array($value)) {
                $value = $this->canonicalPayload($value);
            }
        }

        return $payload;
    }
}
