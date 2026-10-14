<?php

namespace LicenseKit\Database\Factories\Core;

use Illuminate\Database\Eloquent\Factories\Factory;
use LicenseKit\Core\Models\ProductGroup;

/** @extends Factory<ProductGroup> */
class ProductGroupFactory extends Factory
{
    protected $model = ProductGroup::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['name' => fake()->words(2, true)];
    }
}
