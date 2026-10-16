<?php

namespace LicenseKit\Database\Factories\Core;

use Illuminate\Database\Eloquent\Factories\Factory;
use LicenseKit\Core\Actions\SerialKey;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\LicenseKey;

/** @extends Factory<LicenseKey> */
class LicenseKeyFactory extends Factory
{
    protected $model = LicenseKey::class;

    public function definition(): array
    {
        return ['batch_id' => Batch::factory(), 'key' => app(SerialKey::class)->generate()];
    }
}
