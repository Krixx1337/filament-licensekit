<?php

namespace LicenseKit\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Casts\UtcDateTime;

/**
 * @property int $id
 * @property int|string $user_id
 * @property int $product_id
 * @property string $device
 * @property string $user_fingerprint
 * @property CarbonImmutable $expires_at
 */
class LoaderSession extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'licensekit_loader_sessions';

    protected $fillable = ['token_hash', 'user_id', 'product_id', 'device', 'user_fingerprint', 'expires_at', 'last_seen_at'];

    protected $hidden = ['token_hash', 'device', 'user_fingerprint'];

    protected function casts(): array
    {
        return ['created_at' => UtcDateTime::class, 'updated_at' => UtcDateTime::class, 'product_id' => 'integer', 'expires_at' => UtcDateTime::class, 'last_seen_at' => UtcDateTime::class];
    }
}
