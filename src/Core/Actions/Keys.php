<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LicenseKit\Core\Data\IssueContext;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;

class Keys
{
    public function __construct(private SerialKey $serial, private ActivityLog $audit) {}

    public function issue(LicensePlan $plan, int $quantity, IssueContext $context): Batch
    {
        $reference = $context->reference ?? (string) Str::uuid();
        Validator::make(['quantity' => $quantity, 'reference' => $reference, 'source' => $context->source, 'label' => $context->label, 'custom_days' => $context->customDays], [
            'quantity' => ['required', 'integer', 'min:1', 'max:'.config('licensekit.max_batch_size')],
            'reference' => ['required', 'string', 'max:128'], 'source' => ['required', 'in:admin,api,app'],
            'label' => ['nullable', 'string', 'max:255'], 'custom_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ])->validate();
        $identity = hash('sha256', json_encode([$context->source, $context->connection?->id, $reference], JSON_THROW_ON_ERROR));
        $requestHash = hash('sha256', json_encode([$plan->id, $quantity, $context->customDays], JSON_THROW_ON_ERROR));
        $operation = function () use ($plan, $quantity, $context, $reference, $identity, $requestHash): Batch {
            $batch = Batch::where('issuance_key', $identity)->first();
            if ($batch !== null) {
                if ($batch->request_hash !== $requestHash) {
                    throw new LicensingException('batch_conflict', 409);
                }

                return $batch->load('keys');
            }
            $plan = $plan->fresh() ?? throw new LicensingException('plan_unavailable');
            if (! $plan->is_active || $plan->product->status === Product::DISCONTINUED) {
                throw new LicensingException('plan_unavailable');
            }
            $days = LicenseDuration::days($context->customDays ?? $plan->getRawOriginal('duration_days'));
            $batch = Batch::create(['source' => $context->source, 'reference' => $reference, 'issuance_key' => $identity,
                'plan_id' => $plan->id, 'quantity' => $quantity, 'duration_days' => $days, 'request_hash' => $requestHash,
                'label' => $context->label, 'api_connection_id' => $context->connection?->id,
                'created_by' => $context->actor === null ? (($context->connection === null ? 'App' : $context->connection->name)) : (string) $context->actor
                    ->getAttribute(config('licensekit.user_label'))]);
            $this->generate($plan, $quantity, $batch->id);
            $this->audit->record($context->actor === null ? $context->source : 'user:'.$context->actor->getKey(), 'keys_generated', $batch, ['before' => [],
                'after' => ['quantity' => $quantity], 'note' => null]);

            return $batch->load('keys');
        };
        try {
            return DB::transaction($operation, 3);
        } catch (UniqueConstraintViolationException $exception) {
            return DB::transaction(function () use ($identity, $requestHash, $exception): Batch {
                // A locking read sees the winner even inside an older MySQL host snapshot.
                $batch = Batch::where('issuance_key', $identity)->lockForUpdate()->first();
                if ($batch === null) {
                    throw $exception;
                }
                if ($batch->request_hash !== $requestHash) {
                    throw new LicensingException('batch_conflict', 409);
                }
                $batch->setRelation('keys', $batch->keys()->orderBy('id')->lockForUpdate()->get());

                return $batch;
            }, 3);
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
                    $unique[$value] = true;
                    $rows[] = ['key' => $value, 'batch_id' => $batchId, 'created_at' => now(), 'updated_at' => now()];
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
}
