<?php

namespace LicenseKit\Database\Factories\Core;

use Illuminate\Database\Eloquent\Factories\Factory;
use LicenseKit\Core\Models\ApiConnection;

/** @extends Factory<ApiConnection> */
class ApiConnectionFactory extends Factory
{
    protected $model = ApiConnection::class;

    public function definition(): array
    {
        return ['name' => fake()->company(), 'is_active' => true, 'allowed_product_ids' => null, 'max_per_request' => 100];
    }
}
