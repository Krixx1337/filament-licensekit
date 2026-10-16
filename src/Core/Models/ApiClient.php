<?php

namespace LicenseKit\Core\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Database\Factories\Core\ApiClientFactory;

/**
 * @property int $id
 * @property string $name
 * @property bool $is_enabled
 * @property bool $all_products
 * @property list<int> $allowed_product_ids
 * @property list<string> $permissions
 * @property int $maximum_quantity
 * @property Model|null $owner
 */
class ApiClient extends Model
{
    protected $fillable = ['name', 'is_enabled', 'all_products', 'allowed_product_ids', 'permissions', 'maximum_quantity'];

    use HasApiTokens {
        createToken as private createClientToken;
    }

    /** @use HasFactory<ApiClientFactory> */
    use HasFactory;

    protected $table = 'licensekit_api_clients';

    protected $attributes = ['is_enabled' => true, 'all_products' => false, 'allowed_product_ids' => '[]', 'permissions' => '[]', 'maximum_quantity' => 100];

    /** @return array<string, string> */
    public static function permissionOptions(): array
    {
        return ['licenses:create' => 'Create keys', 'keys:read' => 'Read serials', 'batches:read-all' => 'Read other issuers\' batches'];
    }

    protected static function booted(): void
    {
        static::saving(function (ApiClient $client): void {
            Validator::make($client->only($client->getFillable()), [
                'name' => ['required', 'string', 'max:255'], 'is_enabled' => ['required', 'boolean'], 'all_products' => ['required', 'boolean'],
                'allowed_product_ids' => ['present', 'array', 'list'], 'allowed_product_ids.*' => ['integer', 'min:1', 'distinct'],
                'permissions' => ['present', 'array', 'list'], 'permissions.*' => ['string', 'distinct', Rule::in(array_keys(self::permissionOptions()))],
                'maximum_quantity' => ['required', 'integer', 'min:1', 'max:'.config('licensekit.maximum_batch_size')],
            ])->validate();
        });
    }

    protected static function newFactory(): ApiClientFactory
    {
        return ApiClientFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'all_products' => 'boolean', 'allowed_product_ids' => 'array', 'permissions' => 'array',
            'maximum_quantity' => 'integer'];
    }

    /** @param list<string> $abilities */
    public function createToken(string $name, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null): NewAccessToken
    {
        return DB::transaction(function () use ($name, $abilities, $expiresAt): NewAccessToken {
            $client = self::whereKey($this->getKey())->lockForUpdate()->first() ?? throw new LicensingException('management_client_unavailable', 404);

            return $client->createClientToken($name, $abilities, $expiresAt);
        }, 3);
    }
}
