<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Users\ConfiguredUserModel;
use LicenseKit\Core\Users\UserAccess;

class ResellerBatches
{
    public function __construct(private BatchInventoryQueries $inventory, private ManageKeys $keys, private UserAccess $users) {}

    /** @return LengthAwarePaginator<int, Batch> */
    public function paginate(Model $user, int $perPage = 25): LengthAwarePaginator
    {
        $this->inventory->pagination($perPage, 1);

        return $this->inventory->counts($this->query($user))->with('plan.product')->orderByDesc('id')->paginate($perPage);
    }

    /** @return list<string> */
    public function unusedKeys(Model $user, Batch $batch): array
    {
        $owned = $this->query($user)->whereKey($batch->getKey())->first() ?? abort(404);

        return $this->keys->exportUnusedBatch($owned);
    }

    /** @return Builder<Batch> */
    private function query(Model $user): Builder
    {
        $model = ConfiguredUserModel::className();
        $user = $user instanceof $model ? $user->fresh() : null;
        if ($user === null || $this->users->suspended($user)) {
            throw new LicensingException('user_unavailable', 403);
        }

        return Batch::where('reseller_id', $user->getKey());
    }
}
