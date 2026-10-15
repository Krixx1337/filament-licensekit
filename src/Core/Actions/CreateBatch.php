<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LicenseKit\Core\Contracts\ReadinessCheck;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;

class CreateBatch
{
    public function __construct(private SerialKey $serial, private Audit $audit, private CanonicalKeys $canonical, private ReadinessCheck $readiness) {}

    /** @internal Use createFor for admin calls or ManagementOperations for machine calls. */
    public function create(LicensePlan $plan, int $quantity, string $reference, string $issuer, ?string $label = null): Batch
    {
        $this->readiness->assertReady();
        if (trim($issuer) === '' || strlen($issuer) > 255) {
            throw new LicensingException('invalid_issuer', 422);
        }
        $label = $label === null ? null : trim($label);
        if ($label !== null && mb_strlen($label) > 255) {
            throw new LicensingException('invalid_batch_label', 422);
        }
        $label = $label === '' ? null : $label;

        $operation = function () use ($plan, $quantity, $reference, $issuer, $label): Batch {
            if (trim($reference) === '' || strlen($reference) > 128) {
                throw new LicensingException('invalid_reference', 422);
            }
            Product::whereKey($plan->product_id)->lockForUpdate()->firstOrFail();
            $plan = LicensePlan::whereKey($plan->id)->lockForUpdate()->first() ?? throw new LicensingException('plan_unavailable');
            $batch = Batch::where('issuer', $issuer)->where('reference', $reference)->lockForUpdate()->first();
            if ($batch !== null) {
                if ($batch->license_plan_id !== $plan->id || $batch->quantity !== $quantity) {
                    throw new LicensingException('batch_conflict', 409);
                }

                return $batch->load(['keys' => fn ($query) => $query->orderBy('id')->lockForUpdate()]);
            }
            $this->validatePlan($plan, $quantity);
            $batch = Batch::create(['issuer' => $issuer, 'reference' => $reference, 'license_plan_id' => $plan->id,
                'quantity' => $quantity, 'duration_days' => $plan->duration_days, 'label' => $label]);
            $this->generate($plan, $quantity, $batch->id);
            $this->audit->record($issuer, 'batch_created', $batch, [], ['quantity' => $quantity, 'plan_id' => $plan->id,
                'duration_days' => $plan->duration_days]);

            return $batch->load(['keys' => fn ($query) => $query->orderBy('id')->lockForUpdate()]);
        };
        try {
            return DB::transaction($operation, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getSql(), (new Batch)->getTable())) {
                throw $exception;
            }

            return DB::transaction($operation, 3);
        }
    }

    private function validatePlan(LicensePlan $plan, int $quantity): void
    {
        if (! $plan->is_enabled || ! $plan->product->is_enabled) {
            throw new LicensingException('plan_unavailable');
        }
        LicenseDuration::days($plan->getRawOriginal('duration_days'));
        if ($quantity < 1 || $quantity > config('licensekit.maximum_batch_size')) {
            throw new LicensingException('invalid_quantity_or_duration', 422);
        }
    }

    private function generate(LicensePlan $plan, int $quantity, int $batchId): void
    {
        foreach (array_chunk(range(1, $quantity), 250) as $chunk) {
            $inserted = false;
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $rows = [];
                $unique = [];
                foreach ($chunk as $unused) {
                    $value = $this->serial->generate();
                    if ($this->canonical->normalize($value) !== $value) {
                        throw new LicensingException('invalid_key_normalizer', 503);
                    }
                    $unique[$value] = true;
                    $rows[] = ['license_plan_id' => $plan->id, 'key' => $value, 'duration_days' => $plan->duration_days, 'batch_id' => $batchId, 'created_at' => now(), 'updated_at' => now()];
                }
                if (count($unique) !== count($chunk)) {
                    continue;
                }
                try {
                    DB::transaction(fn () => LicenseKey::insert($rows));
                    $inserted = true;
                    break;
                } catch (UniqueConstraintViolationException) {
                    // Retry serial collisions through the database's unique constraint.
                }
            }
            if (! $inserted) {
                throw new LicensingException('key_generation_failed', 503);
            }
        }
    }

    public function createFor(Model $actor, LicensePlan $plan, int $quantity, string $reference, ?string $label = null): Batch
    {
        return DB::transaction(function () use ($actor, $plan, $quantity, $reference, $label): Batch {
            $actor = $actor->newQuery()->whereKey($actor->getKey())->lockForUpdate()->first() ?? throw new LicensingException('batch_forbidden');
            Product::whereKey($plan->product_id)->lockForUpdate()->firstOrFail();
            $plan = LicensePlan::whereKey($plan->id)->lockForUpdate()->first() ?? throw new LicensingException('plan_unavailable');
            if (! app(AdminAuthorization::class)->allows($actor, 'createKeys', $plan)) {
                throw new LicensingException('batch_forbidden');
            }

            return $this->create($plan, $quantity, $reference, 'user:'.$actor->getKey(), $label);
        }, 3);
    }
}
