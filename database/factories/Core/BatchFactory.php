<?php

namespace LicenseKit\Database\Factories\Core;

use Illuminate\Database\Eloquent\Factories\Factory;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\LicensePlan;

/** @extends Factory<Batch> */
class BatchFactory extends Factory
{
    protected $model = Batch::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['request_hash' => fake()->sha256(), 'source' => 'admin', 'created_by' => 'Admin', 'issuance_key' => fake()->unique()->sha256(), 'reference' => fake()->uuid(), 'plan_id' => LicensePlan::factory(),
            'quantity' => 1,
            'duration_days' => fn (array $attributes): ?int => LicensePlan::whereKey($attributes['plan_id'])->firstOrFail()->duration_days,
            'label' => null, 'api_connection_id' => null];
    }
}
