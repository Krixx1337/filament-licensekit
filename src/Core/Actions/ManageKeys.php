<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\LicenseKey;

class ManageKeys
{
    public function __construct(private ActivityLog $audit) {}

    public function revokeKey(LicenseKey $key, Model $actor, ?string $reason = null): void
    {
        DB::transaction(function () use ($key, $actor, $reason): void {
            $key = LicenseKey::whereKey($key->id)->lockForUpdate()->first() ?? throw new LicensingException('invalid_key', 422);
            if ($key->redeemed_at !== null) {
                throw new LicensingException('key_revocation_forbidden');
            }
            $key->update(['cancelled_at' => now()]);
            $this->audit->record('user:'.$actor->getKey(), 'key_cancelled', $key, ['before' => [], 'after' => ['revoked' => true], 'note' => $reason]);
        });
    }

    public function updateNote(LicenseKey $key, Model $actor, ?string $note): void
    {
        $note = trim($note ?? '');
        if (mb_strlen($note) > 255) {
            throw new LicensingException('invalid_key_note', 422);
        }
        DB::transaction(function () use ($key, $actor, $note): void {
            $key = LicenseKey::whereKey($key->id)->lockForUpdate()->firstOrFail();
            $before = $key->only(['note']);
            $key->update(['note' => $note === '' ? null : $note]);
            $this->audit->record('user:'.$actor->getKey(), 'key_note_updated', $key, ['before' => $before, 'after' => $key->only(['note']), 'note' => null]);
        }, 3);
    }

    public function cancelUnusedBatch(Batch $batch, Model $actor, ?string $reason = null): int
    {
        $reason = trim($reason ?? '');
        if (mb_strlen($reason) > 2000) {
            throw new LicensingException('invalid_revocation_reason', 422);
        }

        return DB::transaction(function () use ($batch, $actor, $reason): int {
            $batch = Batch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $count = $batch->keys()->whereNull('redeemed_at')->whereNull('cancelled_at')->update(['cancelled_at' => now('UTC')]);
            $this->audit->record('user:'.$actor->getKey(), 'batch_unused_cancelled', $batch, ['before' => [], 'after' => ['cancelled_count' => $count], 'note' => $reason]);

            return $count;
        }, 3);
    }

    /** @return list<string> */
    public function exportUnusedBatch(Batch $batch): array
    {
        return array_values($batch->keys()->whereNull('redeemed_at')->whereNull('cancelled_at')->orderBy('id')->pluck('key')->all());
    }
}
