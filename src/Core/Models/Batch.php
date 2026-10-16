<?php

namespace LicenseKit\Core\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LicenseKit\Core\Casts\UtcDateTime;
use LicenseKit\Database\Factories\Core\BatchFactory;

/**
 * @property int $id
 * @property string $source
 * @property string $created_by
 * @property string $request_hash
 * @property string $reference
 * @property int $plan_id
 * @property int $quantity
 * @property int|null $duration_days
 * @property string|null $label
 * @property int|null $api_connection_id
 * @property LicensePlan $plan
 * @property Collection<int, LicenseKey> $keys
 * @property-read int|null $redeemed_count from BatchResource's withCount
 * @property-read int|null $unused_count
 * @property-read int|null $cancelled_count
 */
class Batch extends Model
{
    protected $fillable = ['source', 'created_by', 'issuance_key', 'request_hash', 'reference', 'plan_id', 'quantity', 'duration_days', 'label', 'api_connection_id'];

    protected $table = 'licensekit_batches';

    protected static function newFactory(): BatchFactory
    {
        return BatchFactory::new();
    }

    /** @use HasFactory<BatchFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => UtcDateTime::class, 'updated_at' => UtcDateTime::class, 'plan_id' => 'integer', 'quantity' => 'integer', 'duration_days' => 'integer',
            'api_connection_id' => 'integer'];
    }

    /** @return BelongsTo<ApiConnection, $this> */
    public function apiConnection(): BelongsTo
    {
        return $this->belongsTo(ApiConnection::class);
    }

    /** @return HasMany<LicenseKey, $this> */
    public function keys(): HasMany
    {
        return $this->hasMany(LicenseKey::class);
    }

    /** @return BelongsTo<LicensePlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(LicensePlan::class, 'plan_id');
    }
}
