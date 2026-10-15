<?php

namespace LicenseKit\Database\Factories\Core;

use Illuminate\Database\Eloquent\Factories\Factory;
use LicenseKit\Core\Models\ApiClient;

/** @extends Factory<ApiClient> */
class ApiClientFactory extends Factory
{
    protected $model = ApiClient::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['name' => fake()->company(), 'is_enabled' => true, 'all_products' => false, 'allowed_product_ids' => [],
            'permissions' => [], 'maximum_quantity' => 100, 'owner_user_id' => null, 'retired_at' => null];
    }
}
