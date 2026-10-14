<?php

namespace LicenseKit\Tests\Core\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;
use LicenseKit\Core\Casts\HwidPayload;
use LicenseKit\Core\Models\Product;
use LicenseKit\Core\Users\ConfiguredUserModel;

/**
 * @property int $id
 * @property int|string $user_id
 * @property int $product_id
 * @property int|null $personal_access_token_id
 * @property Model $user
 * @property Product $product
 * @property string $user_fingerprint
 * @property array<string, mixed> $hwid
 */
#[Fillable(['user_id', 'product_id', 'personal_access_token_id', 'hwid', 'user_fingerprint'])]
#[Hidden(['hwid', 'personal_access_token_id', 'user_fingerprint'])]
class ClientSession extends Model
{
    protected $table = 'client_sessions';

    protected static function newFactory(): ClientSessionFactory
    {
        return ClientSessionFactory::new();
    }

    /** @use HasFactory<ClientSessionFactory> */
    use HasApiTokens, HasFactory;

    /** @return BelongsTo<Model&Authenticatable, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(ConfiguredUserModel::className(), 'user_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['product_id' => 'integer', 'personal_access_token_id' => 'integer', 'hwid' => HwidPayload::class];
    }
}
