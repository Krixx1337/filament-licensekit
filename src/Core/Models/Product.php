<?php

namespace LicenseKit\Core\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LicenseKit\Core\Observers\LicensingConfigurationObserver;
use LicenseKit\Database\Factories\Core\ProductFactory;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int|null $product_group_id
 * @property ProductGroup|null $group
 * @property bool $is_enabled
 * @property bool $is_blocked
 * @property Collection<int, LicensePlan> $plans
 */
#[Fillable(['name', 'slug', 'product_group_id', 'is_enabled', 'is_blocked'])]
#[ObservedBy([LicensingConfigurationObserver::class])]
class Product extends Model
{
    /** @internal Actor supplied by supported catalog operations for synchronous auditing. */
    public ?string $auditActor = null;

    protected $table = 'licensekit_products';

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $attributes = ['is_enabled' => true, 'is_blocked' => false];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['product_group_id' => 'integer', 'is_enabled' => 'boolean', 'is_blocked' => 'boolean'];
    }

    /** @return BelongsTo<ProductGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'product_group_id');
    }

    /** @return HasMany<LicensePlan, $this> */
    public function plans(): HasMany
    {
        return $this->hasMany(LicensePlan::class);
    }
}
