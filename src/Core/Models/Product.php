<?php

namespace LicenseKit\Core\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LicenseKit\Core\Observers\LicensingConfigurationObserver;
use LicenseKit\Database\Factories\Core\ProductFactory;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_enabled
 * @property bool $is_blocked
 * @property bool $requires_hwid
 * @property Collection<int, LicensePlan> $plans
 */
#[ObservedBy([LicensingConfigurationObserver::class])]
class Product extends Model
{
    protected $fillable = ['name', 'slug', 'is_enabled', 'is_blocked', 'requires_hwid'];

    /** @internal Actor supplied by supported catalog operations for synchronous auditing. */
    public ?string $auditActor = null;

    protected $table = 'licensekit_products';

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $attributes = ['is_enabled' => true, 'is_blocked' => false, 'requires_hwid' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'is_blocked' => 'boolean', 'requires_hwid' => 'boolean'];
    }

    /** @return HasMany<LicensePlan, $this> */
    public function plans(): HasMany
    {
        return $this->hasMany(LicensePlan::class);
    }
}
