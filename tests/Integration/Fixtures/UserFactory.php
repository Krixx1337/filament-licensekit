<?php

namespace LicenseKit\Tests\Integration\Fixtures;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return ['email' => fake()->unique()->safeEmail(), 'password' => 'password', 'email_verified_at' => now(), 'is_suspended' => false];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
