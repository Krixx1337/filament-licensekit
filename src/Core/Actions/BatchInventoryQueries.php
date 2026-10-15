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
            'keys as claimed_count' => fn (Builder $keys) => $keys->whereNotNull('claimed_at'),
            'keys as unclaimed_count' => fn (Builder $keys) => $keys->whereNull('claimed_at')->whereNull('revoked_at'),
            'keys as revoked_count' => fn (Builder $keys) => $keys->whereNotNull('revoked_at'),
        ]);
    }

    public function pagination(int $perPage, int $page): void
    {
        if ($perPage < 1 || $perPage > 100 || $page < 1) {
            throw new LicensingException('invalid_page_size', 422);
        }
    }

    /** @return LengthAwarePaginator<int, array{id: int, key: string, duration_days: int|null, claimed_at: string|null, revoked_at: string|null}> */
    public function keys(Batch $batch, int $perPage, int $page, string $status): LengthAwarePaginator
    {
        $this->pagination($perPage, $page);
        if (! in_array($status, ['all', 'unclaimed', 'claimed', 'revoked'], true)) {
            throw new LicensingException('invalid_key_status', 422);
        }
        $query = $batch->keys();
        match ($status) {
            'unclaimed' => $query->whereNull('claimed_at')->whereNull('revoked_at'),
            'claimed' => $query->whereNotNull('claimed_at'),
            'revoked' => $query->whereNotNull('revoked_at'),
            default => null,
        };

        return $query->orderBy('id')->paginate($perPage, ['*'], 'page', $page)->through(fn (LicenseKey $key): array => [
            'id' => $key->id, 'key' => $key->key, 'duration_days' => $key->duration_days,
            'claimed_at' => $key->claimed_at?->toIso8601String(), 'revoked_at' => $key->revoked_at?->toIso8601String(),
        ]);
    }
}
