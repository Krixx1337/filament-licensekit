<?php

namespace LicenseKit\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LicenseKit\Core\Casts\UtcDateTime;

/**
 * @property int $id
 * @property int|null $product_id
 * @property string $device
 * @property string $device_hash
 */
class DeviceBan extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'licensekit_device_bans';

    protected $fillable = ['product_id', 'device', 'device_hash', 'note', 'created_by'];

    protected $hidden = ['device', 'device_hash'];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return ['created_at' => UtcDateTime::class, 'updated_at' => UtcDateTime::class, 'product_id' => 'integer'];
    }
}
