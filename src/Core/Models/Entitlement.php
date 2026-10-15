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
 * @property bool $is_revoked
 * @property bool $is_blocked
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $paused_at
 * @property int|null $remaining_seconds
 * @property Product $product
 * @property Model|null $user
 * @property DeviceBinding|null $binding
 */
class Entitlement extends Model
{
    protected $fillable = ['user_id', 'product_id', 'is_lifetime', 'is_revoked', 'is_blocked', 'expires_at', 'paused_at', 'remaining_seconds'];

    protected $table = 'licensekit_entitlements';

    protected static function newFactory(): EntitlementFactory
    {
        return EntitlementFactory::new();
    }

    /** @use HasFactory<EntitlementFactory> */
    use HasFactory;

    protected $attributes = ['is_lifetime' => false, 'is_revoked' => false, 'is_blocked' => false];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => UtcDateTime::class, 'updated_at' => UtcDateTime::class, 'product_id' => 'integer', 'is_lifetime' => 'boolean', 'is_revoked' => 'boolean', 'is_blocked' => 'boolean', 'expires_at' => UtcDateTime::class, 'paused_at' => UtcDateTime::class, 'remaining_seconds' => 'integer'];
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
