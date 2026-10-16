<?php

namespace LicenseKit\Tests\Core\Fixtures;

use Illuminate\Database\Eloquent\Factories\Factory;
use LicenseKit\Core\Models\Product;

/** @extends Factory<ClientSession> */
class ClientSessionFactory extends Factory
{
    protected $model = ClientSession::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['product_id' => Product::factory(), 'personal_access_token_id' => null, 'user_fingerprint' => str_repeat('a', 64), 'hwid' => 'device'];
    }
}
