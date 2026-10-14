<?php

namespace LicenseKit\Database\Factories\Core;

use Illuminate\Database\Eloquent\Factories\Factory;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;

/** @extends Factory<LicensePlan> */
class LicensePlanFactory extends Factory
{
    protected $model = LicensePlan::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['product_id' => Product::factory(), 'name' => '30 days', 'duration_days' => 30, 'is_enabled' => true];
    }

    public function lifetime(): static
    {
        return $this->state(fn (): array => ['duration_days' => null, 'name' => 'Lifetime']);
    }
}
