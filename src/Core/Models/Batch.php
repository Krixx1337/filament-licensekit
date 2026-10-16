<?php

namespace LicenseKit\Core\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Users\ConfiguredUserModel;
use LicenseKit\Database\Factories\Core\BatchFactory;

/**
 * @property int $id
 * @property string $issuer
 * @property string $reference
 * @property int $license_plan_id
 * @property int $quantity
 * @property int|null $duration_days
 * @property string|null $label
 * @property int|null $api_client_id
 * @property int|string|null $created_by_user_id
 * @property LicensePlan $plan
 * @property Collection<int, LicenseKey> $keys
 */
class Batch extends Model
{
    protected $fillable = ['issuer', 'reference', 'license_plan_id', 'quantity', 'duration_days', 'label', 'api_client_id', 'created_by_user_id'];

    protected $table = 'licensekit_batches';

    protected static function booted(): void
    {
        static::updating(function (Batch $batch): void {
            if ($batch->isDirty(['api_client_id', 'created_by_user_id'])) {
                throw new LicensingException('batch_attribution_immutable', 409);
            }
        });
    }

    protected static function newFactory(): BatchFactory
    {
        return BatchFactory::new();
    }

    /** @use HasFactory<BatchFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['license_plan_id' => 'integer', 'quantity' => 'integer', 'duration_days' => 'integer', 'api_client_id' => 'integer'];
    }

    /** @return BelongsTo<ApiClient, $this> */
    public function apiClient(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class);
    }

    /** @return BelongsTo<Model&Authenticatable, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(ConfiguredUserModel::className(), 'created_by_user_id');
    }

    /** @return HasMany<LicenseKey, $this> */
    public function keys(): HasMany
    {
        return $this->hasMany(LicenseKey::class);
    }

    /** @return BelongsTo<LicensePlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(LicensePlan::class, 'license_plan_id');
    }
}
