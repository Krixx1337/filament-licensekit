<?php

namespace LicenseKit\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LicenseKit\Core\Casts\UtcDateTime;
use LicenseKit\Core\Users\ConfiguredUserModel;
use LicenseKit\Database\Factories\Core\LicenseFactory;

/**
 * @property int $id
 * @property int|string|null $user_id
 * @property int $product_id
 * @property bool $is_lifetime
 * @property string $status active, paused, suspended or revoked
 * @property CarbonImmutable|null $suspended_until
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $paused_at
 * @property int|null $remaining_seconds
 * @property Product $product
 * @property Model|null $user
 * @property string|null $device
 * @property CarbonImmutable|null $device_bound_at
 * @property CarbonImmutable|null $device_reset_at
 */
class License extends Model
{
    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const SUSPENDED = 'suspended';

    public const REVOKED = 'revoked';

    protected $fillable = ['user_id', 'product_id', 'is_lifetime', 'status', 'suspended_until', 'expires_at', 'paused_at', 'remaining_seconds', 'device', 'device_bound_at', 'device_reset_at'];

    protected $hidden = ['device'];

    protected $table = 'licensekit_licenses';

    protected static function newFactory(): LicenseFactory
    {
        return LicenseFactory::new();
    }

    /** @use HasFactory<LicenseFactory> */
    use HasFactory;

    protected $attributes = ['is_lifetime' => false, 'status' => self::ACTIVE];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => UtcDateTime::class, 'updated_at' => UtcDateTime::class, 'product_id' => 'integer', 'is_lifetime' => 'boolean', 'suspended_until' => UtcDateTime::class, 'expires_at' => UtcDateTime::class, 'paused_at' => UtcDateTime::class, 'remaining_seconds' => 'integer', 'device_bound_at' => UtcDateTime::class, 'device_reset_at' => UtcDateTime::class];
    }

    /** Suspension ends by itself once suspended_until has passed. */
    public function isSuspended(): bool
    {
        return $this->status === self::SUSPENDED && ($this->suspended_until === null || $this->suspended_until->isFuture());
    }

    public function isRevoked(): bool
    {
        return $this->status === self::REVOKED;
    }

    /** The status to return to when a suspension or cancellation is lifted. */
    public function restingStatus(): string
    {
        return $this->paused_at === null ? self::ACTIVE : self::PAUSED;
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Model&Authenticatable, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(ConfiguredUserModel::className(), 'user_id');
    }
}
