<?php

namespace LicenseKit\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LicenseKit\Core\Casts\UtcDateTime;
use LicenseKit\Database\Factories\Core\ProductFactory;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $status active, maintenance or discontinued
 * @property CarbonImmutable|null $free_from
 * @property CarbonImmutable|null $free_until
 * @property bool $device_lock
 * @property int|null $device_move_cooldown_days days between customer device moves; null = support only
 * @property Collection<int, LicensePlan> $plans
 */
class Product extends Model
{
    /** Sold and usable. */
    public const ACTIVE = 'active';

    /** The software stops working for everyone; licenses keep their time. */
    public const MAINTENANCE = 'maintenance';

    /** No new keys; existing licenses keep working. */
    public const DISCONTINUED = 'discontinued';

    public const STATUSES = [self::ACTIVE, self::MAINTENANCE, self::DISCONTINUED];

    protected $fillable = ['name', 'slug', 'status', 'device_lock', 'device_move_cooldown_days', 'free_from', 'free_until'];

    protected $table = 'licensekit_products';

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $attributes = ['status' => self::ACTIVE, 'device_lock' => true, 'device_move_cooldown_days' => 7];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => UtcDateTime::class, 'updated_at' => UtcDateTime::class, 'free_from' => UtcDateTime::class, 'free_until' => UtcDateTime::class,
            'device_lock' => 'boolean', 'device_move_cooldown_days' => 'integer'];
    }

    public function freeAccessActive(): bool
    {
        return $this->free_from !== null && $this->free_until !== null
            && $this->free_from->lessThanOrEqualTo(now()) && $this->free_until->isFuture();
    }

    /** @return HasMany<License, $this> */
    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    /** @return HasMany<LicensePlan, $this> */
    public function plans(): HasMany
    {
        return $this->hasMany(LicensePlan::class);
    }
}
