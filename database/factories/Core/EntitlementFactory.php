<?php

namespace LicenseKit\Database\Factories\Core;

use Illuminate\Database\Eloquent\Factories\Factory;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\Product;

/** @extends Factory<Entitlement> */
class EntitlementFactory extends Factory
{
    protected $model = Entitlement::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['product_id' => Product::factory(), 'expires_at' => now()->addDays(30), 'is_lifetime' => false, 'status' => Entitlement::ACTIVE];
    }

    public function lifetime(): static
    {
        return $this->state(fn (): array => ['is_lifetime' => true, 'expires_at' => null]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }

    public function paused(int $seconds = 86400): static
    {
        return $this->state(fn (): array => ['is_lifetime' => false, 'expires_at' => null, 'status' => Entitlement::PAUSED, 'paused_at' => now('UTC')->startOfSecond(), 'remaining_seconds' => $seconds]);
    }
}
