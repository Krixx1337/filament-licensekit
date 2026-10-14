<?php

namespace LicenseKit\Core\Data;

use Laravel\Sanctum\PersonalAccessToken;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\ApiClient;

final readonly class ManagementAuthorization
{
    public function __construct(public ApiClient $client, public PersonalAccessToken $token) {}

    public function allows(string $permission): bool
    {
        return in_array($permission, $this->client->permissions, true) && in_array($permission, $this->token->abilities, true);
    }

    public function requirePermission(string $permission): void
    {
        if (! $this->allows($permission)) {
            throw new LicensingException('management_forbidden');
        }
    }

    public function includesProduct(int $productId): bool
    {
        return $this->client->all_products || in_array($productId, $this->client->allowed_product_ids, true);
    }

    public function issuer(): string
    {
        return 'api:'.$this->client->id;
    }
}
