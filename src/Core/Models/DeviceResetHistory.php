<?php

namespace LicenseKit\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LicenseKit\Core\Casts\HwidPayload;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Users\ConfiguredUserModel;
use LicenseKit\Database\Factories\Core\DeviceResetHistoryFactory;

/**
 * @property int $id
 * @property string $user_id
 * @property string $scope
 * @property int|null $product_id
 * @property CarbonImmutable $reset_at
 * @property string $performed_by
 * @property string $actor_id
 * @property string|null $reason
 * @property Model|null $user
 * @property Model|null $actor
 * @property Product|null $product
 * @property array<string, mixed>|null $before_hwid
 */
class DeviceResetHistory extends Model
{
    protected $fillable = ['user_id', 'scope', 'product_id', 'reset_at', 'performed_by', 'actor_id', 'reason', 'before_hwid'];

    protected $hidden = ['before_hwid'];

    /** @use HasFactory<DeviceResetHistoryFactory> */
    use HasFactory;

    protected $table = 'licensekit_device_reset_history';

    public $timestamps = false;

    protected static function newFactory(): DeviceResetHistoryFactory
    {
        return DeviceResetHistoryFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LicensingException('reset_history_is_immutable', 409);
        });
        static::deleting(function (DeviceResetHistory $history): void {
            throw new LicensingException('reset_history_is_immutable', 409);
        });
    }

    /** @return Attribute<CarbonImmutable|null, mixed> */
    protected function resetAt(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?CarbonImmutable => $value === null ? null : CarbonImmutable::parse($value, 'UTC'),
            set: fn (mixed $value): string => CarbonImmutable::parse($value)->utc()->startOfSecond()->format('Y-m-d H:i:s'),
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['user_id' => 'string', 'actor_id' => 'string', 'product_id' => 'integer', 'reset_at' => 'immutable_datetime', 'before_hwid' => HwidPayload::class];
    }

    /** @return BelongsTo<Model&Authenticatable, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(ConfiguredUserModel::className(), 'user_id');
    }

    /** @return BelongsTo<Model&Authenticatable, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(ConfiguredUserModel::className(), 'actor_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
