<?php

namespace LicenseKit\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LicenseKit\Core\Actions\LicenseDuration;
use LicenseKit\Database\Factories\Core\LicenseKeyFactory;

/**
 * @property int $id
 * @property int $license_plan_id
 * @property int $batch_id
 * @property int|null $entitlement_id
 * @property string $key
 * @property string|null $note
 * @property int|null $duration_days
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable|null $revoked_at
 * @property LicensePlan $plan
 * @property Batch $batch
 */
#[Fillable(['license_plan_id', 'batch_id', 'entitlement_id', 'key', 'note', 'duration_days', 'claimed_at', 'revoked_at'])]
#[Hidden(['key', 'note'])]
class LicenseKey extends Model
{
    protected $table = 'licensekit_license_keys';

    protected static function newFactory(): LicenseKeyFactory
    {
        return LicenseKeyFactory::new();
    }

    /** @use HasFactory<LicenseKeyFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (LicenseKey $record): void {
            $record->setAttribute('duration_days', LicenseDuration::days($record->getAttributes()['duration_days'] ?? null));
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['license_plan_id' => 'integer', 'batch_id' => 'integer', 'entitlement_id' => 'integer', 'duration_days' => 'integer', 'claimed_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<LicensePlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(LicensePlan::class, 'license_plan_id');
    }

    /** @return BelongsTo<Batch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }
}
