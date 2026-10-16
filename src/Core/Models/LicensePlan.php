<?php

namespace LicenseKit\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use LicenseKit\Core\Actions\LicenseDuration;
use LicenseKit\Core\Casts\UtcDateTime;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Database\Factories\Core\LicensePlanFactory;

/**
 * @property int $id
 * @property int $product_id
 * @property string $name
 * @property int|null $duration_days
 * @property bool $is_active
 * @property Product $product
 */
class LicensePlan extends Model
{
    protected $fillable = ['product_id', 'name', 'duration_days', 'is_active'];

    protected $table = 'licensekit_plans';

    protected $attributes = ['is_active' => true];

    protected static function newFactory(): LicensePlanFactory
    {
        return LicensePlanFactory::new();
    }

    /** @use HasFactory<LicensePlanFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => UtcDateTime::class, 'updated_at' => UtcDateTime::class, 'product_id' => 'integer', 'duration_days' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasManyThrough<LicenseKey, Batch, $this> */
    public function keys(): HasManyThrough
    {
        return $this->hasManyThrough(LicenseKey::class, Batch::class, 'plan_id', 'batch_id');
    }

    protected static function booted(): void
    {
        static::saving(function (LicensePlan $record): void {
            $record->setAttribute('duration_days', LicenseDuration::days($record->getAttributes()['duration_days'] ?? null));
        });

        static::updating(function (LicensePlan $plan): void {
            if ($plan->isDirty('product_id') && $plan->keys()->exists()) {
                throw new LicensingException('plan_product_is_immutable', 409);
            }
        });
    }
}
