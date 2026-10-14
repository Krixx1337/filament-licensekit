<?php

namespace LicenseKit\Database\Factories\Core;

use Illuminate\Database\Eloquent\Factories\Factory;
use LicenseKit\Core\Actions\DeviceBindings;
use LicenseKit\Core\Models\DeviceBinding;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\Product;

/** @extends Factory<DeviceBinding> */
class DeviceBindingFactory extends Factory
{
    protected $model = DeviceBinding::class;

    public function forEntitlement(Entitlement $entitlement): static
    {
        return $this->state(fn (): array => ['user_id' => $entitlement->user_id, 'scope_key' => app(DeviceBindings::class)->scopeKey($entitlement->product)]);
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['scope_key' => fn () => config('licensekit.device_binding.scope') === 'global' ? 'global' : 'product:'.Product::factory()->create()->id, 'bound_at' => now(), 'hwid' => ['value' => 'device']];
    }
}
