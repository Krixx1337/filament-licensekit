<?php

namespace LicenseKit\Modules\Outages\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use LicenseKit\Core\Actions\AdminAuthorization;
use LicenseKit\Core\Actions\Audit;
use LicenseKit\Core\Events\EntitlementGranted;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Product;
use LicenseKit\Modules\Outages\Jobs\ApplyCompensation;
use LicenseKit\Modules\Outages\Models\CompensationEntry;
use LicenseKit\Modules\Outages\Models\LicensingOperation;
use LicenseKit\Modules\Outages\Models\Outage;
use LicenseKit\Support\Modules;
use Throwable;

class ManageOutages
{
    public function __construct(private Audit $audit) {}

    public function start(Product $product, string $reference, string $reason, Model $actor, bool $block = false): Outage
    {
        $this->validate($reference, $reason);

        return DB::transaction(function () use ($product, $reference, $reason, $actor, $block): Outage {
            $actor = $this->actor($actor);
            $product = $this->product($product->id);
            $actor = $this->authorize($actor, 'startOutage', $product);
            $hash = $this->hash([$product->id, $reason, $block]);
            $existing = Outage::where('reference', $reference)->lockForUpdate()->first();
            if ($existing !== null) {
                $this->same($existing->request_hash, $hash);

                return $existing;
            }
            $this->assertNoConflict($product->id);
            $at = CarbonImmutable::now('UTC')->startOfSecond();
            $outage = Outage::create(['product_id' => $product->id, 'reference' => $reference, 'request_hash' => $hash, 'reason' => $reason, 'access_blocked' => $block, 'started_at' => $at]);
            $operation = LicensingOperation::create(['product_id' => $product->id, 'outage_id' => $outage->id, 'kind' => 'outage', 'reference' => $reference, 'request_hash' => $hash, 'reason' => $reason, 'actor' => 'user:'.$actor->getKey(), 'status' => 'active']);
            $query = $this->eligible($product->id, $at);
            $seconds = DB::getDriverName() === 'sqlite'
                ? "CAST(strftime('%s', expires_at) AS INTEGER) - CAST(strftime('%s', ?) AS INTEGER)"
                : 'TIMESTAMPDIFF(SECOND, ?, expires_at)';
            $query->selectRaw('? AS operation_id, id AS entitlement_id, '.$seconds.' AS seconds', [$operation->id, $at->format('Y-m-d H:i:s')]);
            DB::table('licensekit_compensation_entries')->insertUsing(['operation_id', 'entitlement_id', 'seconds'], $query);
            $operation->update(['target_count' => CompensationEntry::where('operation_id', $operation->id)->count()]);
            $this->audit->record('user:'.$actor->getKey(), 'outage_started', $outage, [], ['product_id' => $product->id, 'access_blocked' => $block, 'target_count' => $operation->target_count], $reason);

            return $outage;
        }, 3);
    }

    public function stop(Outage $outage, bool $compensate, Model $actor): Outage
    {
        return DB::transaction(function () use ($outage, $compensate, $actor): Outage {
            $actor = $this->actor($actor);
            $this->product($outage->product_id);
            $outage = Outage::whereKey($outage->id)->lockForUpdate()->firstOrFail();
            $actor = $this->authorize($actor, 'stop', $outage);
            if ($outage->ended_at !== null || $outage->stopped_at !== null) {
                return $outage;
            }
            $at = CarbonImmutable::now('UTC')->startOfSecond();
            $operation = $outage->operation;
            $outage->update(['stopped_at' => $at, 'ended_at' => $compensate ? null : $at]);
            $operation->update(['status' => $compensate ? 'pending' : 'canceled']);
            $this->audit->record('user:'.$actor->getKey(), 'outage_stopped', $outage, [], ['compensate' => $compensate]);
            if ($compensate) {
                $this->dispatch($operation);
            }

            return $outage;
        }, 3);
    }

    public function preview(Product $product, CarbonImmutable $eligibleAt, Model $actor): int
    {
        $this->authorize($this->actor($actor), 'addTime', $product->fresh() ?? throw new LicensingException('service_unavailable'));
        $this->validateDate($eligibleAt);

        return $this->eligible($product->id, $eligibleAt)->count();
    }

    public function addTime(Product $product, int $seconds, CarbonImmutable $eligibleAt, string $reference, string $reason, Model $actor): LicensingOperation
    {
        $this->validate($reference, $reason);
        $this->validateDate($eligibleAt);
        Validator::make(['seconds' => $seconds], ['seconds' => ['required', 'integer', 'min:1', 'max:315360000']])->validate();

        return DB::transaction(function () use ($product, $seconds, $eligibleAt, $reference, $reason, $actor): LicensingOperation {
            $actor = $this->actor($actor);
            $product = $this->product($product->id);
            $actor = $this->authorize($actor, 'addTime', $product);
            $eligibleAt = $eligibleAt->utc()->startOfSecond();
            $hash = $this->hash([$product->id, $seconds, $eligibleAt->toIso8601String(), $reason]);
            $existing = LicensingOperation::where('kind', 'manual')->where('reference', $reference)->lockForUpdate()->first();
            if ($existing !== null) {
                $this->same($existing->request_hash, $hash);

                return $existing;
            }
            $this->assertNoConflict($product->id);
            $operation = LicensingOperation::create(['product_id' => $product->id, 'kind' => 'manual', 'reference' => $reference, 'request_hash' => $hash, 'seconds' => $seconds, 'eligible_at' => $eligibleAt, 'reason' => $reason, 'actor' => 'user:'.$actor->getKey(), 'status' => 'pending']);
            $query = $this->eligible($product->id, $eligibleAt)->selectRaw('? AS operation_id, id AS entitlement_id, ? AS seconds', [$operation->id, $seconds]);
            DB::table('licensekit_compensation_entries')->insertUsing(['operation_id', 'entitlement_id', 'seconds'], $query);
            $operation->update(['target_count' => CompensationEntry::where('operation_id', $operation->id)->count()]);
            $this->audit->record('user:'.$actor->getKey(), 'compensation_created', $operation, [], ['seconds' => $seconds, 'target_count' => $operation->target_count, 'eligible_at' => $eligibleAt->toIso8601String()], $reason);
            $this->dispatch($operation);

            return $operation;
        }, 3);
    }

    public function retry(LicensingOperation $operation, Model $actor): void
    {
        DB::transaction(function () use ($operation, $actor): void {
            $actor = $this->actor($actor);
            $this->product($operation->product_id);
            $operation = LicensingOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            $actor = $this->authorize($actor, 'retry', $operation);
            if (! in_array($operation->status, ['pending', 'failed'], true)) {
                throw new LicensingException('operation_conflict', 409);
            }
            $operation->update(['status' => 'pending', 'failure' => null]);
            $this->audit->record('user:'.$actor->getKey(), 'compensation_retried', $operation);
            $this->dispatch($operation);
        }, 3);
    }

    public function cancel(LicensingOperation $operation, Model $actor): void
    {
        DB::transaction(function () use ($operation, $actor): void {
            $actor = $this->actor($actor);
            $this->product($operation->product_id);
            $operation = LicensingOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            $actor = $this->authorize($actor, 'cancel', $operation);
            if (! in_array($operation->status, ['pending', 'failed'], true)) {
                throw new LicensingException('operation_conflict', 409);
            }
            $operation->update(['status' => 'canceled', 'failure' => null]);
            $operation->outage?->update(['ended_at' => now('UTC')]);
            $this->audit->record('user:'.$actor->getKey(), 'compensation_canceled', $operation);
        }, 3);
    }

    /** Synchronous listener joins the claim transaction, already holding the product lock. */
    public function trackGrant(EntitlementGranted $event): void
    {
        Modules::requireEnabled('outages');
        if ($event->seconds === null) {
            return;
        }
        $outage = Outage::where('product_id', $event->entitlement->product_id)->whereNull('ended_at')->whereNull('stopped_at')->lockForUpdate()->first();
        if ($outage === null) {
            return;
        }
        $operation = $outage->operation;
        $entry = CompensationEntry::firstOrCreate(['operation_id' => $operation->id, 'entitlement_id' => $event->entitlement->id], ['seconds' => 0]);
        $entry->increment('seconds', $event->seconds);
        if ($entry->wasRecentlyCreated) {
            $operation->increment('target_count');
        }
    }

    private function actor(Model $actor): Model
    {
        Modules::requireEnabled('outages');

        return $actor->newQuery()->whereKey($actor->getKey())->sharedLock()->first() ?? throw new LicensingException('outage_forbidden');
    }

    private function authorize(Model $actor, string $ability, Model $subject): Model
    {
        if (! app(AdminAuthorization::class)->allows($actor, $ability, $subject)) {
            throw new LicensingException('outage_forbidden');
        }

        return $actor;
    }

    private function product(int $id): Product
    {
        return Product::whereKey($id)->lockForUpdate()->first() ?? throw new LicensingException('service_unavailable');
    }

    private function assertNoConflict(int $productId): void
    {
        if (Outage::where('product_id', $productId)->whereNull('ended_at')->lockForUpdate()->first() !== null
            || LicensingOperation::where('product_id', $productId)->whereIn('status', ['pending', 'processing', 'failed'])->lockForUpdate()->first() !== null) {
            throw new LicensingException('operation_conflict', 409);
        }
    }

    private function eligible(int $productId, CarbonImmutable $at): Builder
    {
        return DB::table('licensekit_entitlements')->where('product_id', $productId)->whereNotNull('user_id')
            ->whereNull('paused_at')->where('is_lifetime', false)->where('is_revoked', false)->where('created_at', '<=', $at)->where('expires_at', '>', $at);
    }

    private function validate(string $reference, string $reason): void
    {
        Validator::make(compact('reference', 'reason'), ['reference' => ['required', 'string', 'max:128'], 'reason' => ['required', 'string', 'max:2000']])->validate();
    }

    private function validateDate(CarbonImmutable $at): void
    {
        if ($at->isFuture()) {
            throw new LicensingException('invalid_eligibility_date', 422);
        }
    }

    /** @param list<mixed> $values */
    private function hash(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    private function same(string $original, string $new): void
    {
        if (! hash_equals($original, $new)) {
            throw new LicensingException('operation_conflict', 409);
        }
    }

    private function dispatch(LicensingOperation $operation): void
    {
        DB::afterCommit(function () use ($operation): void {
            try {
                ApplyCompensation::dispatch($operation->id);
            } catch (Throwable $exception) {
                LicensingOperation::whereKey($operation->id)->where('status', 'pending')->update(['status' => 'failed', 'failure' => 'dispatch_failed']);
                report($exception);
            }
        });
    }
}
