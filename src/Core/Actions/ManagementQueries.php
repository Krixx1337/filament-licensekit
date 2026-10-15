<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use LicenseKit\Core\Data\ManagementAuthorization;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;

class ManagementQueries
{
    public function __construct(private ManagementAccess $access, private BatchInventoryQueries $inventory) {}

    /** @return LengthAwarePaginator<int, Product> */
    public function products(#[\SensitiveParameter] string $bearer, int $perPage = 25, int $page = 1, ?string $search = null): LengthAwarePaginator
    {
        $this->pagination($perPage, $page);
        if ($search !== null && mb_strlen($search) > 255) {
            throw new LicensingException('invalid_search', 422);
        }

        return $this->access->run($bearer, null, function (ManagementAuthorization $context) use ($perPage, $page, $search): LengthAwarePaginator {
            $query = Product::query()->with('group');
            if (! $context->client->all_products) {
                $query->whereIn('id', $context->client->allowed_product_ids);
            }
            if ($search !== null && $search !== '') {
                $query->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('slug', 'like', '%'.$search.'%'));
            }

            return $query->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
        });
    }

    /** @return LengthAwarePaginator<int, LicensePlan> */
    public function plans(#[\SensitiveParameter] string $bearer, int $perPage = 25, int $page = 1, ?int $productId = null): LengthAwarePaginator
    {
        $this->pagination($perPage, $page);

        return $this->access->run($bearer, null, function (ManagementAuthorization $context) use ($perPage, $page, $productId): LengthAwarePaginator {
            $query = $this->plansFor($context);
            if ($productId !== null) {
                $query->where('product_id', $productId);
            }

            return $query->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
        });
    }

    /** @return LengthAwarePaginator<int, Batch> */
    public function batches(#[\SensitiveParameter] string $bearer, int $perPage = 25, int $page = 1, ?int $planId = null, ?string $reference = null): LengthAwarePaginator
    {
        $this->pagination($perPage, $page);

        return $this->access->run($bearer, null, function (ManagementAuthorization $context) use ($perPage, $page, $planId, $reference): LengthAwarePaginator {
            $query = $this->batchesFor($context);
            if ($planId !== null) {
                $query->where('license_plan_id', $planId);
            }
            if ($reference !== null) {
                $query->where('reference', $reference);
            }

            return $query->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
        });
    }

    public function batch(#[\SensitiveParameter] string $bearer, int $batchId): Batch
    {
        return $this->access->run($bearer, null, fn (ManagementAuthorization $context): Batch => $this->batchesFor($context)->whereKey($batchId)->firstOrFail());
    }

    /** @return LengthAwarePaginator<int, array{id: int, key: string, duration_days: int|null, claimed_at: string|null, revoked_at: string|null}> */
    public function keys(#[\SensitiveParameter] string $bearer, int $batchId, int $perPage = 25, int $page = 1, string $status = 'all'): LengthAwarePaginator
    {
        return $this->access->run($bearer, 'keys:read', function (ManagementAuthorization $context) use ($batchId, $perPage, $page, $status): LengthAwarePaginator {
            $batch = $this->batchesFor($context)->whereKey($batchId)->firstOrFail();

            return $this->inventory->keys($batch, $perPage, $page, $status);
        });
    }

    /** @return Builder<LicensePlan> */
    private function plansFor(ManagementAuthorization $context): Builder
    {
        $query = LicensePlan::query();
        if (! $context->client->all_products) {
            $query->whereIn('product_id', $context->client->allowed_product_ids);
        }

        return $query;
    }

    /** @return Builder<Batch> */
    private function batchesFor(ManagementAuthorization $context): Builder
    {
        $query = Batch::query()->whereIn('license_plan_id', $this->plansFor($context)->select('id'));
        if (! $context->allows('batches:read-all')) {
            $query->where(function (Builder $visible) use ($context): void {
                $visible->where('issuer', $context->issuer());
                if ($context->client->owner_user_id !== null) {
                    $visible->orWhere('reseller_user_id', $context->client->owner_user_id);
                }
            });
        }

        return $this->inventory->counts($query);
    }

    private function pagination(int $perPage, int $page): void
    {
        $this->inventory->pagination($perPage, $page);
    }
}
