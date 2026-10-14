<?php

namespace LicenseKit\Core\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Validator;
use LicenseKit\Core\Observers\LicensingConfigurationObserver;
use LicenseKit\Database\Factories\Core\ProductGroupFactory;

/**
 * @property int $id
 * @property string $name
 */
#[ObservedBy([LicensingConfigurationObserver::class])]
class ProductGroup extends Model
{
    protected $fillable = ['name'];

    /** @internal Actor supplied by supported catalog operations for synchronous auditing. */
    public ?string $auditActor = null;

    protected $table = 'licensekit_product_groups';

    /** @use HasFactory<ProductGroupFactory> */
    use HasFactory;

    protected static function newFactory(): ProductGroupFactory
    {
        return ProductGroupFactory::new();
    }

    protected static function booted(): void
    {
        static::saving(function (ProductGroup $group): void {
            $name = $group->getAttribute('name');
            if (is_string($name)) {
                $name = trim($name);
            }
            Validator::make(['name' => $name], ['name' => ['required', 'string', 'max:255']])->validate();
            $group->setAttribute('name', $name);
        });
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'product_group_id');
    }
}
