<?php

namespace LicenseKit\Database\Factories\Core;

use Illuminate\Database\Eloquent\Factories\Factory;
use LicenseKit\Core\Models\DeviceResetHistory;

/** @extends Factory<DeviceResetHistory> */
class DeviceResetHistoryFactory extends Factory
{
    protected $model = DeviceResetHistory::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => fake()->uuid(),
            'scope' => 'global', 'product_id' => null, 'reset_at' => now('UTC'),
            'performed_by' => 'customer', 'actor_id' => fn (array $attributes): string => (string) $attributes['user_id'], 'reason' => null,
        ];
    }
}
