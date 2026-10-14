<?php

namespace LicenseKit\Database\Factories\Core;

use Illuminate\Database\Eloquent\Factories\Factory;
use LicenseKit\Core\Actions\SerialKey;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LicensePlan;

/** @extends Factory<LicenseKey> */
class LicenseKeyFactory extends Factory
{
    protected $model = LicenseKey::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['license_plan_id' => LicensePlan::factory(), 'batch_id' => fn (array $attributes): int => Batch::factory()->create(['license_plan_id' => $attributes['license_plan_id'], 'duration_days' => $attributes['duration_days']])->id, 'key' => app(SerialKey::class)->generate(), 'duration_days' => 30];
    }
}
