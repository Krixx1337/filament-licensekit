<?php

namespace LicenseKit\Core\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LicenseKit\Core\Casts\UtcDateTime;
use LicenseKit\Core\Users\ConfiguredUserModel;
use LicenseKit\Database\Factories\Core\ApiConnectionFactory;

/**
 * @property int|string|null $user_id
 * @property Model|null $user
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property list<int>|null $allowed_product_ids
 * @property int $max_per_request
 * @property string|null $token_hash
 */
class ApiConnection extends Model
{
    /** @use HasFactory<ApiConnectionFactory> */
    use HasFactory;

    protected $table = 'licensekit_api_connections';

    protected $fillable = ['user_id', 'name', 'is_active', 'allowed_product_ids', 'max_per_request'];

    protected $hidden = ['token_hash'];

    protected $attributes = ['is_active' => true, 'max_per_request' => 100];

    /** @return BelongsTo<Model&Authenticatable, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(ConfiguredUserModel::className(), 'user_id');
    }

    protected static function newFactory(): ApiConnectionFactory
    {
        return ApiConnectionFactory::new();
    }

    protected function casts(): array
    {
        return ['created_at' => UtcDateTime::class, 'updated_at' => UtcDateTime::class, 'is_active' => 'boolean', 'allowed_product_ids' => 'array', 'max_per_request' => 'integer',
            'last_used_at' => UtcDateTime::class];
    }

    public function includesProduct(int $productId): bool
    {
        return $this->allowed_product_ids === null || in_array($productId, $this->allowed_product_ids, true);
    }
}
