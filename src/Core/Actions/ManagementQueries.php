<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use LicenseKit\Core\Data\ManagementAuthorization;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;

class ManagementQueries
{
    public function __construct(private BatchInventoryQueries $inventory) {}

    /** @return LengthAwarePaginator<int, Product> */
    public function products(ManagementAuthorization $context, int $perPage = 25, int $page = 1, ?string $search = null): LengthAwarePaginator
    {
        $this->inventory->pagination($perPage, $page);

        return Product::query()->when($context->client->allowed_product_ids !== null, fn ($q) => $q->whereIn('id', $context->client->allowed_product_ids))
            ->when($search !== null && $search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('slug', 'like', '%'.$search.'%')))
            ->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
    }

    /** @return LengthAwarePaginator<int, LicensePlan> */
    public function plans(ManagementAuthorization $context, int $perPage = 25, int $page = 1, ?int $productId = null): LengthAwarePaginator
    {
        $this->inventory->pagination($perPage, $page);

        return LicensePlan::query()->when($context->client->allowed_product_ids !== null, fn ($q) => $q->whereIn('product_id', $context->client->allowed_product_ids))
            ->when($productId !== null, fn ($q) => $q->where('product_id', $productId))->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
    }

    /** @return LengthAwarePaginator<int, Batch> */
    public function batches(ManagementAuthorization $context, int $perPage = 25, int $page = 1, ?int $planId = null, ?string $reference = null): LengthAwarePaginator
    {
        $this->inventory->pagination($perPage, $page);

        return $this->inventory->counts($this->batchesFor($context))->when($planId !== null, fn ($q) => $q->where('plan_id', $planId))
            ->when($reference !== null, fn ($q) => $q->where('reference', $reference))->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function batch(ManagementAuthorization $context, int $batchId): Batch
    {
        return $this->batchesFor($context)->whereKey($batchId)->firstOrFail();
    }

    /** @return LengthAwarePaginator<int, array{id: int, key: string, duration_days: int|null, redeemed_at: string|null, cancelled_at: string|null}> */
    public function keys(ManagementAuthorization $context, int $batchId, int $perPage = 25, int $page = 1, string $status = 'all'): LengthAwarePaginator
    {
        return $this->inventory->keys($this->batch($context, $batchId), $perPage, $page, $status);
    }

    /** @return Builder<Batch> */
    private function batchesFor(ManagementAuthorization $context): Builder
    {
        return Batch::where('api_connection_id', $context->client->id);
    }
}
