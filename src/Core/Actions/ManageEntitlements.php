<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Database\Eloquent\Model;
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
    public function __construct(private Audit $audit) {}

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
