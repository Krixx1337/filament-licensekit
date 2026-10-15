<?php

namespace LicenseKit\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Casts\HwidPayload;
use LicenseKit\Core\Casts\UtcDateTime;
use LicenseKit\Database\Factories\Core\DeviceBindingFactory;

/**
 * @property int $id
 * @property int|string $user_id
 * @property string $scope_key
 * @property array<string, mixed>|null $hwid
 * @property CarbonImmutable|null $bound_at
 * @property CarbonImmutable|null $last_hwid_reset_at
 */
class DeviceBinding extends Model
{
    protected $fillable = ['user_id', 'scope_key', 'hwid', 'bound_at', 'last_hwid_reset_at'];

    protected $hidden = ['hwid'];

    protected $table = 'licensekit_device_bindings';

    protected static function newFactory(): DeviceBindingFactory
    {
        return DeviceBindingFactory::new();
    }

    /** @use HasFactory<DeviceBindingFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['hwid' => HwidPayload::class, 'bound_at' => UtcDateTime::class, 'last_hwid_reset_at' => UtcDateTime::class];
    }
}
