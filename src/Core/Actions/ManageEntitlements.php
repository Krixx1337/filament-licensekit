<?php

namespace LicenseKit\Core\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LicenseKit\Core\Events\EntitlementAccessInvalidated;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\Product;

class ManageEntitlements
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
            $this->authorize($actor, 'addTime', $product);
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

    /** @return Builder<Entitlement> */
    private function addTimeTargets(Product $product, CarbonImmutable $since): Builder
    {
        return Entitlement::query()->where('product_id', $product->id)->whereNotNull('user_id')
            ->where('is_lifetime', false)->where('is_revoked', false)
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

    public function extend(Entitlement $entitlement, int $seconds, Model $actor, string $reason): void
    {
        if ($seconds < 1 || $seconds > 315360000) {
            throw new LicensingException('invalid_duration', 422);
        }
        $this->changeClock($entitlement, 'extend', $actor, $reason, $seconds);
    }

    public function pause(Entitlement $entitlement, Model $actor, string $reason): void
    {
        $this->changeClock($entitlement, 'pause', $actor, $reason);
    }

    public function resume(Entitlement $entitlement, Model $actor, string $reason): void
    {
        $this->changeClock($entitlement, 'resume', $actor, $reason);
    }

    /** @param 'extend'|'pause'|'resume' $ability */
    private function changeClock(Entitlement $entitlement, string $ability, Model $actor, string $reason, ?int $seconds = null): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw new LicensingException('invalid_reason', 422);
        }

        DB::transaction(function () use ($entitlement, $ability, $actor, $reason, $seconds): void {
            $users = $actor->newQuery()->whereKey(array_filter([$actor->getKey(), $entitlement->user_id], fn ($id): bool => $id !== null))
                ->orderBy($actor->getKeyName())->lockForUpdate()->get();
            $actor = $users->first(fn (Model $user): bool => (string) $user->getKey() === (string) $actor->getKey()) ?? throw new LicensingException('admin_forbidden');
            Product::whereKey($entitlement->product_id)->lockForUpdate()->firstOrFail();
            $entitlement = Entitlement::whereKey($entitlement->id)->lockForUpdate()->first() ?? throw new LicensingException('license_unavailable');
            $this->authorize($actor, $ability, $entitlement);
            if ($entitlement->user_id === null || $entitlement->is_lifetime || $entitlement->is_revoked) {
                throw new LicensingException('cannot_'.$ability.'_entitlement', 409);
            }
            $before = $entitlement->only(['expires_at', 'paused_at', 'remaining_seconds']);
            $clock = app(EntitlementClock::class);
            match ($ability) {
                'extend' => $clock->extend($entitlement, $seconds ?? throw new LicensingException('invalid_duration', 422)),
                'pause' => $clock->pause($entitlement),
                'resume' => $clock->resume($entitlement),
            };
            if ($ability === 'pause') {
                Event::dispatch(new EntitlementAccessInvalidated($entitlement));
            }
            $after = $entitlement->only(['expires_at', 'paused_at', 'remaining_seconds']);
            if ($seconds !== null) {
                $after['added_seconds'] = $seconds;
            }
            $event = match ($ability) {
                'extend' => 'entitlement_extended',
                'pause' => 'entitlement_paused',
                'resume' => 'entitlement_resumed',
            };
            $this->audit->record('user:'.$actor->getKey(), $event, $entitlement, $before, $after, $reason);
        }, 3);
    }

    public function status(Entitlement $entitlement, string $field, bool $value, Model $actor, string $reason): void
    {
        DB::transaction(function () use ($entitlement, $field, $value, $actor, $reason): void {
            $actor->newQuery()->whereKey(array_filter([$actor->getKey(), $entitlement->user_id], fn ($id): bool => $id !== null))->orderBy($actor->getKeyName())->lockForUpdate()->get();
            if (! in_array($field, ['is_revoked', 'is_blocked'], true) || trim($reason) === '') {
                throw new LicensingException('invalid_status_change', 422);
            }
            Product::whereKey($entitlement->product_id)->lockForUpdate()->firstOrFail();
            $entitlement = Entitlement::whereKey($entitlement->id)->lockForUpdate()->first() ?? throw new LicensingException('license_unavailable');
            $this->authorize($actor, $field === 'is_revoked' ? ($value ? 'revoke' : 'restoreAccess') : ($value ? 'block' : 'unblock'), $entitlement);
            $before = $entitlement->only([$field]);
            $entitlement->update([$field => $value]);
            if ($value) {
                Event::dispatch(new EntitlementAccessInvalidated($entitlement));
            }
            $this->audit->record('user:'.$actor->getKey(), 'entitlement_status_changed', $entitlement, $before, [$field => $value], $reason);
        });
    }

    public function revokeKey(LicenseKey $key, Model $actor, string $reason): void
    {
        DB::transaction(function () use ($key, $actor, $reason): void {
            $actor = $actor->newQuery()->whereKey($actor->getKey())->sharedLock()->first() ?? throw new LicensingException('admin_forbidden');
            Product::whereKey($key->plan->product_id)->lockForUpdate()->firstOrFail();
            $key = LicenseKey::whereKey($key->id)->lockForUpdate()->first() ?? throw new LicensingException('invalid_key', 422);
            $this->authorize($actor, 'revoke', $key);
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
            $actor = $actor->newQuery()->whereKey($actor->getKey())->sharedLock()->first() ?? throw new LicensingException('admin_forbidden');
            Product::whereKey($key->plan->product_id)->lockForUpdate()->firstOrFail();
            $key = LicenseKey::whereKey($key->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, 'updateNote', $key);
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
            $actor = $actor->newQuery()->whereKey($actor->getKey())->sharedLock()->first() ?? throw new LicensingException('admin_forbidden');
            Product::whereKey($batch->plan->product_id)->lockForUpdate()->firstOrFail();
            $batch = Batch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, 'revokeUnclaimed', $batch);
            $count = $batch->keys()->whereNull('claimed_at')->whereNull('revoked_at')->update(['revoked_at' => now('UTC')]);
            $this->audit->record('user:'.$actor->getKey(), 'batch_unclaimed_revoked', $batch, [], ['revoked_count' => $count], $reason);

            return $count;
        }, 3);
    }

    /** @return list<string> */
    public function exportUnclaimedBatch(Batch $batch, Model $actor): array
    {
        return DB::transaction(function () use ($batch, $actor): array {
            $batch = Batch::whereKey($batch->id)->firstOrFail();
            $this->authorize($actor, 'exportUnclaimed', $batch);

            return array_values($batch->keys()->whereNull('claimed_at')->whereNull('revoked_at')->orderBy('id')->pluck('key')->all());
        });
    }

    /** @param class-string<Model>|Model $subject */
    private function authorize(Model $actor, string $ability, string|Model $subject): void
    {
        $actor = $actor->newQuery()->whereKey($actor->getKey())->sharedLock()->first() ?? throw new LicensingException('admin_forbidden');
        if (! app(AdminAuthorization::class)->allows($actor, $ability, $subject)) {
            throw new LicensingException('admin_forbidden');
        }
    }
}
