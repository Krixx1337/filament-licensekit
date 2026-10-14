<?php

namespace LicenseKit\Database\Factories\Outages;

use Illuminate\Database\Eloquent\Factories\Factory;
use LicenseKit\Core\Models\Product;
use LicenseKit\Modules\Outages\Models\Outage;

/** @extends Factory<Outage> */
class OutageFactory extends Factory
{
    protected $model = Outage::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['reference' => fake()->uuid(), 'request_hash' => hash('sha256', fake()->uuid()), 'product_id' => Product::factory(), 'reason' => 'Maintenance', 'access_blocked' => false, 'started_at' => now()];
    }
}
