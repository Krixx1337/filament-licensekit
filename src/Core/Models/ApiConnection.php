<?php

namespace LicenseKit\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Casts\UtcDateTime;
use LicenseKit\Database\Factories\Core\ApiConnectionFactory;

/**
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

    protected $fillable = ['name', 'is_active', 'allowed_product_ids', 'max_per_request'];

    protected $hidden = ['token_hash'];

    protected $attributes = ['is_active' => true, 'max_per_request' => 100];

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
