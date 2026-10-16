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
 * @property string $status active, maintenance or discontinued
 * @property bool $requires_hwid
 * @property int|null $device_move_cooldown_days days between customer device moves; null = support only
 * @property Collection<int, LicensePlan> $plans
 */
#[ObservedBy([LicensingConfigurationObserver::class])]
class Product extends Model
{
    /** Sold and usable. */
    public const ACTIVE = 'active';

    /** The software stops working for everyone; licenses keep their time. */
    public const MAINTENANCE = 'maintenance';

    /** No new keys; existing licenses keep working. */
    public const DISCONTINUED = 'discontinued';

    public const STATUSES = [self::ACTIVE, self::MAINTENANCE, self::DISCONTINUED];

    protected $fillable = ['name', 'slug', 'status', 'requires_hwid', 'device_move_cooldown_days'];

    /** @internal Actor supplied by supported catalog operations for synchronous auditing. */
    public ?string $auditActor = null;

    protected $table = 'licensekit_products';

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $attributes = ['status' => self::ACTIVE, 'requires_hwid' => true, 'device_move_cooldown_days' => 7];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['requires_hwid' => 'boolean', 'device_move_cooldown_days' => 'integer'];
    }

    /** @return HasMany<LicensePlan, $this> */
    public function plans(): HasMany
    {
        return $this->hasMany(LicensePlan::class);
    }
}
