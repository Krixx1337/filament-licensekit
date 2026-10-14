<?php

namespace LicenseKit\Database\Factories\Core;

use Illuminate\Database\Eloquent\Factories\Factory;
use LicenseKit\Core\Models\LicensingBan;
use LicenseKit\Core\Models\Product;

/** @extends Factory<LicensingBan> */
class LicensingBanFactory extends Factory
{
    protected $model = LicensingBan::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['product_id' => Product::factory(), 'reason' => fake()->sentence(), 'created_by' => 'test:factory'];
    }
}
