<?php

namespace LicenseKit\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LicenseKit\Core\Actions\DeviceBindings;
use LicenseKit\Core\Casts\UtcDateTime;
use LicenseKit\Core\Users\ConfiguredUserModel;
use LicenseKit\Database\Factories\Core\EntitlementFactory;

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
 * @property DeviceBinding|null $binding
 */
class Entitlement extends Model
{
    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const SUSPENDED = 'suspended';

    public const REVOKED = 'revoked';

    protected $fillable = ['user_id', 'product_id', 'is_lifetime', 'status', 'suspended_until', 'expires_at', 'paused_at', 'remaining_seconds'];

    protected $table = 'licensekit_entitlements';

    protected static function newFactory(): EntitlementFactory
    {
        return EntitlementFactory::new();
    }

    /** @use HasFactory<EntitlementFactory> */
    use HasFactory;

    protected $attributes = ['is_lifetime' => false, 'status' => self::ACTIVE];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => UtcDateTime::class, 'updated_at' => UtcDateTime::class, 'product_id' => 'integer', 'is_lifetime' => 'boolean', 'suspended_until' => UtcDateTime::class, 'expires_at' => UtcDateTime::class, 'paused_at' => UtcDateTime::class, 'remaining_seconds' => 'integer'];
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

    /** @return Attribute<DeviceBinding|null, never> */
    protected function binding(): Attribute
    {
        return Attribute::get(fn (): ?DeviceBinding => $this->user === null ? null : ($this->relationLoaded('binding') ? $this->getRelation('binding') : app(DeviceBindings::class)->find($this->user, $this->product)));
    }
}
