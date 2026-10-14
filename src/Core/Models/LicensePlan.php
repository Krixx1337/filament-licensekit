<?php

namespace LicenseKit\Core\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LicenseKit\Core\Actions\LicenseDuration;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Observers\LicensingConfigurationObserver;
use LicenseKit\Database\Factories\Core\LicensePlanFactory;

/**
 * @property int $id
 * @property int $product_id
 * @property string $name
 * @property int|null $duration_days
 * @property bool $is_enabled
 * @property Product $product
 */
#[Fillable(['product_id', 'name', 'duration_days', 'is_enabled'])]
#[ObservedBy([LicensingConfigurationObserver::class])]
class LicensePlan extends Model
{
    /** @internal Actor supplied by supported catalog operations for synchronous auditing. */
    public ?string $auditActor = null;

    protected $table = 'licensekit_license_plans';

    protected $attributes = ['is_enabled' => true];

    protected static function newFactory(): LicensePlanFactory
    {
        return LicensePlanFactory::new();
    }

    /** @use HasFactory<LicensePlanFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['product_id' => 'integer', 'duration_days' => 'integer', 'is_enabled' => 'boolean'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<LicenseKey, $this> */
    public function keys(): HasMany
    {
        return $this->hasMany(LicenseKey::class);
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
