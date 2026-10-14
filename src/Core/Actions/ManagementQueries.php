<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use LicenseKit\Core\Data\ManagementAuthorization;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;

class ManagementQueries
{
    public function __construct(private ManagementAccess $access) {}

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

    /** @return list<array{id: int, key: string}> */
    public function keys(#[\SensitiveParameter] string $bearer, int $batchId): array
    {
        return $this->access->run($bearer, 'keys:read', function (ManagementAuthorization $context) use ($batchId): array {
            $batch = $this->batchesFor($context)->whereKey($batchId)->firstOrFail();

            return array_values($batch->keys()->orderBy('id')->get()->map(fn (LicenseKey $key): array => ['id' => $key->id, 'key' => $key->key])->all());
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
            $query->where('issuer', $context->issuer());
        }

        return $query;
    }

    private function pagination(int $perPage, int $page): void
    {
        if ($perPage < 1 || $perPage > 100 || $page < 1) {
            throw new LicensingException('invalid_page_size', 422);
        }
    }
}
