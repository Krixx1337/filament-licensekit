<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Users\ConfiguredUserModel;
use LicenseKit\Core\Users\UserAccess;

/** Host callers must supply their authenticated reseller, never an untrusted user ID. */
class ResellerInventory
{
    public function __construct(private BatchInventoryQueries $queries, private UserAccess $users) {}

    /** @return LengthAwarePaginator<int, Batch> */
    public function batches(Model $reseller, int $perPage = 25, int $page = 1, ?int $planId = null): LengthAwarePaginator
    {
        $query = $this->batchesFor($reseller);
        $this->queries->pagination($perPage, $page);
        if ($planId !== null) {
            $query->where('license_plan_id', $planId);
        }

        return $query->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function batch(Model $reseller, int $batchId): Batch
    {
        return $this->batchesFor($reseller)->whereKey($batchId)->firstOrFail();
    }

    /** @return LengthAwarePaginator<int, array{id: int, key: string, duration_days: int|null, claimed_at: string|null, revoked_at: string|null}> */
    public function keys(Model $reseller, int $batchId, int $perPage = 25, int $page = 1, string $status = 'all'): LengthAwarePaginator
    {
        return $this->queries->keys($this->batch($reseller, $batchId), $perPage, $page, $status);
    }

    /** @return Builder<Batch> */
    private function batchesFor(Model $reseller): Builder
    {
        $model = ConfiguredUserModel::className();
        $configured = new $model;
        if (! $reseller instanceof $model || $reseller->getTable() !== $configured->getTable()
            || ($reseller->getConnectionName() ?? config('database.default')) !== config('database.default')
            || $reseller->getKey() === null) {
            throw new LicensingException('user_unavailable');
        }
        $reseller = $configured->newQuery()->whereKey($reseller->getKey())->first();
        if ($reseller === null || ! $this->users->available($reseller)) {
            throw new LicensingException('user_unavailable');
        }

        return $this->queries->counts(Batch::query()->where('reseller_user_id', $reseller->getKey()))->with('plan.product');
    }
}
