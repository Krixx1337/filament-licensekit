<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\LicenseKey;

/** Shared read queries; callers must authorize the batch before retrieving keys. */
class BatchInventoryQueries
{
    /** @param Builder<Batch> $query
     * @return Builder<Batch> */
    public function counts(Builder $query): Builder
    {
        return $query->withCount([
            'keys as total_count',
            'keys as redeemed_count' => fn (Builder $keys) => $keys->whereNotNull('redeemed_at'),
            'keys as unused_count' => fn (Builder $keys) => $keys->whereNull('redeemed_at')->whereNull('cancelled_at'),
            'keys as cancelled_count' => fn (Builder $keys) => $keys->whereNotNull('cancelled_at'),
        ]);
    }

    public function pagination(int $perPage, int $page): void
    {
        if ($perPage < 1 || $perPage > 100 || $page < 1) {
            throw new LicensingException('invalid_page_size', 422);
        }
    }

    /** @return LengthAwarePaginator<int, array{id: int, key: string, duration_days: int|null, redeemed_at: string|null, cancelled_at: string|null}> */
    public function keys(Batch $batch, int $perPage, int $page, string $status): LengthAwarePaginator
    {
        $this->pagination($perPage, $page);
        if (! in_array($status, ['all', 'unused', 'redeemed', 'cancelled'], true)) {
            throw new LicensingException('invalid_key_status', 422);
        }
        $query = $batch->keys();
        match ($status) {
            'unused' => $query->whereNull('redeemed_at')->whereNull('cancelled_at'),
            'redeemed' => $query->whereNotNull('redeemed_at'),
            'cancelled' => $query->whereNotNull('cancelled_at'),
            default => null,
        };

        return $query->with('batch')->orderBy('id')->paginate($perPage, ['*'], 'page', $page)->through(fn (LicenseKey $key): array => [
            'id' => $key->id, 'key' => $key->key, 'duration_days' => $key->batch->duration_days,
            'redeemed_at' => $key->redeemed_at?->toIso8601String(), 'cancelled_at' => $key->cancelled_at?->toIso8601String(),
        ]);
    }
}
