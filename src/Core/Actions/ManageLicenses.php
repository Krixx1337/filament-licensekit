<?php

namespace LicenseKit\Core\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LicenseKit\Core\Events\LicenseAccessInvalidated;
use LicenseKit\Core\Events\ProductAccessInvalidated;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\Product;

class ManageLicenses
{
    public const MAXIMUM_ADDED_SECONDS = 315360000;

    public function __construct(private Audit $audit) {}

    /**
     * Adds time to every running or paused license of a product, for example after downtime.
     * Paused licenses bank the time. With $since, licenses that expired after that moment also get it.
     */
    public function addTimeToProduct(Product $product, int $seconds, Model $actor, ?string $reason = null, ?CarbonImmutable $since = null): int
    {
        if ($seconds < 1 || $seconds > self::MAXIMUM_ADDED_SECONDS) {
            throw new LicensingException('invalid_duration', 422);
        }
        $since = $this->addTimeCutoff($since);
        $reason = $this->optionalReason($reason);

        return DB::transaction(function () use ($product, $seconds, $actor, $reason, $since): int {
            $product = Product::whereKey($product->id)->lockForUpdate()->first() ?? throw new LicensingException('service_unavailable');
            $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
            // $seconds is a validated integer, so interpolating it into the expression is safe.
            $expiry = DB::getDriverName() === 'sqlite' ? "datetime(expires_at, '+{$seconds} seconds')" : "DATE_ADD(expires_at, INTERVAL {$seconds} SECOND)";
            $running = $this->addTimeTargets($product, $since)->whereNull('paused_at')
                ->update(['expires_at' => new Expression($expiry), 'updated_at' => $now]); // @phpstan-ignore argument.type (validated integer, see above)
            $paused = $this->addTimeTargets($product, $since)->whereNotNull('paused_at')
                ->increment('remaining_seconds', $seconds, ['updated_at' => $now]);
            $this->audit->record('user:'.$actor->getKey(), 'product_time_added', $product, [],
                ['seconds' => $seconds, 'since' => $since->toIso8601String(), 'license_count' => $running + $paused], $reason);

            return $running + $paused;
        }, 3);
    }

    /** Number of licenses addTimeToProduct would currently change. */
    public function previewAddTimeToProduct(Product $product, ?CarbonImmutable $since = null): int
    {
        return $this->addTimeTargets($product, $this->addTimeCutoff($since))->count();
    }

    /** @return Builder<License> */
    private function addTimeTargets(Product $product, CarbonImmutable $since): Builder
    {
        return License::query()->where('product_id', $product->id)->whereNotNull('user_id')
            ->where('is_lifetime', false)->where('status', '!=', License::REVOKED)
            ->where(fn ($query) => $query->whereNotNull('paused_at')->orWhere('expires_at', '>', $since->format('Y-m-d H:i:s')));
    }

    private function addTimeCutoff(?CarbonImmutable $since): CarbonImmutable
    {
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        if ($since !== null && $since->isAfter($now)) {
            throw new LicensingException('invalid_eligibility_date', 422);
        }

        return ($since ?? $now)->utc()->startOfSecond();
    }

    private function optionalReason(?string $reason): ?string
    {
        $reason = trim($reason ?? '');
        if (mb_strlen($reason) > 2000) {
            throw new LicensingException('invalid_reason', 422);
        }

        return $reason === '' ? null : $reason;
    }

    public function extend(License $license, int $seconds, Model $actor, string $reason): void
    {
        if ($seconds < 1 || $seconds > 315360000) {
            throw new LicensingException('invalid_duration', 422);
        }
        $this->changeClock($license, 'extend', $actor, $reason, $seconds);
    }

    public function pause(License $license, Model $actor, string $reason): void
    {
        $this->changeClock($license, 'pause', $actor, $reason);
    }

    public function resume(License $license, Model $actor, string $reason): void
    {
        $this->changeClock($license, 'resume', $actor, $reason);
    }

    /** @param 'extend'|'pause'|'resume' $ability */
    private function changeClock(License $license, string $ability, Model $actor, string $reason, ?int $seconds = null): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw new LicensingException('invalid_reason', 422);
        }

        DB::transaction(function () use ($license, $ability, $actor, $reason, $seconds): void {
            $users = $actor->newQuery()->whereKey(array_filter([$actor->getKey(), $license->user_id], fn ($id): bool => $id !== null))
                ->orderBy($actor->getKeyName())->lockForUpdate()->get();
            $actor = $users->first(fn (Model $user): bool => (string) $user->getKey() === (string) $actor->getKey()) ?? throw new LicensingException('admin_forbidden');
            Product::whereKey($license->product_id)->lockForUpdate()->firstOrFail();
            $license = License::whereKey($license->id)->lockForUpdate()->first() ?? throw new LicensingException('license_unavailable');
            if ($license->user_id === null || $license->is_lifetime || $license->isRevoked()
                || $license->isSuspended()) {
                throw new LicensingException('cannot_'.$ability.'_license', 409);
            }
            $before = $license->only(['expires_at', 'paused_at', 'remaining_seconds']);
            $clock = app(LicenseClock::class);
            match ($ability) {
                'extend' => $clock->extend($license, $seconds ?? throw new LicensingException('invalid_duration', 422)),
                'pause' => $clock->pause($license),
                'resume' => $clock->resume($license),
            };
            if ($ability === 'pause') {
                Event::dispatch(new LicenseAccessInvalidated($license));
            }
            $after = $license->only(['expires_at', 'paused_at', 'remaining_seconds']);
            if ($seconds !== null) {
                $after['added_seconds'] = $seconds;
            }
            $event = match ($ability) {
                'extend' => 'license_extended',
                'pause' => 'license_paused',
                'resume' => 'license_resumed',
            };
            $this->audit->record('user:'.$actor->getKey(), $event, $license, $before, $after, $reason);
        }, 3);
    }

    /** Stops all access to the product, including free access and key redemption, until lifted or $until passes. */
    public function suspend(License $license, Model $actor, ?string $reason = null, ?CarbonImmutable $until = null): void
    {
        $this->changeStatus($license, $actor, $reason, License::SUSPENDED, $until);
    }

    public function unsuspend(License $license, Model $actor, ?string $reason = null): void
    {
        $this->changeStatus($license, $actor, $reason);
    }

    /** Cancels paid access, for example after a refund. Another key cannot renew a cancelled license. */
    public function revoke(License $license, Model $actor, ?string $reason = null): void
    {
        $this->changeStatus($license, $actor, $reason, License::REVOKED);
    }

    public function restore(License $license, Model $actor, ?string $reason = null): void
    {
        $this->changeStatus($license, $actor, $reason);
    }

    /** Suspends a customer for a product, creating their license record if they have none yet. */
    public function suspendCustomer(Model $user, Product $product, Model $actor, ?string $reason = null, ?CarbonImmutable $until = null): License
    {
        return DB::transaction(function () use ($user, $product, $actor, $reason, $until): License {
            $user = $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->first() ?? throw new LicensingException('user_unavailable');
            $license = License::firstOrCreate(['user_id' => $user->getKey(), 'product_id' => $product->id]);
            $this->suspend($license, $actor, $reason, $until);

            return $license->fresh() ?? $license;
        }, 3);
    }

    private function changeStatus(License $license, Model $actor, ?string $reason, ?string $status = null, ?CarbonImmutable $until = null): void
    {
        $reason = $this->optionalReason($reason);
        if ($until !== null && ! $until->isFuture()) {
            throw new LicensingException('invalid_suspension_end', 422);
        }
        DB::transaction(function () use ($license, $actor, $reason, $status, $until): void {
            $actor->newQuery()->whereKey(array_filter([$actor->getKey(), $license->user_id], fn ($id): bool => $id !== null))->orderBy($actor->getKeyName())->lockForUpdate()->get();
            Product::whereKey($license->product_id)->lockForUpdate()->firstOrFail();
            $license = License::whereKey($license->id)->lockForUpdate()->first() ?? throw new LicensingException('license_unavailable');
            $before = $license->only(['status', 'suspended_until']);
            $license->update([
                'status' => $status ?? $license->restingStatus(),
                'suspended_until' => $status === License::SUSPENDED ? $until?->utc()->startOfSecond() : null,
            ]);
            if ($status !== null && $license->user !== null) {
                Event::dispatch(new LicenseAccessInvalidated($license));
                Event::dispatch(new ProductAccessInvalidated($license->user, $license->product_id));
            }
            $this->audit->record('user:'.$actor->getKey(), 'license_status_changed', $license, $before, $license->only(['status', 'suspended_until']), $reason);
        });
    }

    public function revokeKey(LicenseKey $key, Model $actor, string $reason): void
    {
        DB::transaction(function () use ($key, $actor, $reason): void {
            Product::whereKey($key->plan->product_id)->lockForUpdate()->firstOrFail();
            $key = LicenseKey::whereKey($key->id)->lockForUpdate()->first() ?? throw new LicensingException('invalid_key', 422);
            if ($key->claimed_at !== null || trim($reason) === '') {
                throw new LicensingException('key_revocation_forbidden');
            }
            $key->update(['revoked_at' => now()]);
            $this->audit->record('user:'.$actor->getKey(), 'key_revoked', $key, [], ['revoked' => true], $reason);
        });
    }

    public function updateNote(LicenseKey $key, Model $actor, ?string $note): void
    {
        $note = trim($note ?? '');
        if (mb_strlen($note) > 255) {
            throw new LicensingException('invalid_key_note', 422);
        }
        DB::transaction(function () use ($key, $actor, $note): void {
            Product::whereKey($key->plan->product_id)->lockForUpdate()->firstOrFail();
            $key = LicenseKey::whereKey($key->id)->lockForUpdate()->firstOrFail();
            $before = $key->only(['note']);
            $key->update(['note' => $note === '' ? null : $note]);
            $this->audit->record('user:'.$actor->getKey(), 'key_note_updated', $key, $before, $key->only(['note']));
        }, 3);
    }

    public function revokeUnclaimedBatch(Batch $batch, Model $actor, string $reason): int
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw new LicensingException('invalid_revocation_reason', 422);
        }

        return DB::transaction(function () use ($batch, $actor, $reason): int {
            Product::whereKey($batch->plan->product_id)->lockForUpdate()->firstOrFail();
            $batch = Batch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $count = $batch->keys()->whereNull('claimed_at')->whereNull('revoked_at')->update(['revoked_at' => now('UTC')]);
            $this->audit->record('user:'.$actor->getKey(), 'batch_unclaimed_revoked', $batch, [], ['revoked_count' => $count], $reason);

            return $count;
        }, 3);
    }

    /** @return list<string> */
    public function exportUnclaimedBatch(Batch $batch): array
    {
        return array_values($batch->keys()->whereNull('claimed_at')->whereNull('revoked_at')->orderBy('id')->pluck('key')->all());
    }
}
