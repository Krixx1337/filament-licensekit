<?php

namespace LicenseKit\Core\Data;

use LicenseKit\Core\Models\ApiConnection;

final readonly class ManagementAuthorization
{
    public function __construct(public ApiConnection $client) {}

    public function includesProduct(int $productId): bool
    {
        return $this->client->includesProduct($productId);
    }
}
