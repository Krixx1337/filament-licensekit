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
        return ['issuer' => 'admin:1', 'reference' => fake()->uuid(), 'license_plan_id' => LicensePlan::factory(),
            'quantity' => 1,
            'duration_days' => fn (array $attributes): ?int => LicensePlan::whereKey($attributes['license_plan_id'])->firstOrFail()->duration_days,
            'label' => null, 'api_client_id' => null, 'created_by_user_id' => null];
    }
}
