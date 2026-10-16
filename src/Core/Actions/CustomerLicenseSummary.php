<?php

namespace LicenseKit\Core\Actions;

use LicenseKit\Core\Models\Entitlement;

class CustomerLicenseSummary
{
    public function __construct(private EntitlementClock $clock, private DeviceResetEligibility $resets) {}

    /** @return array<string, mixed> */
    public function for(Entitlement $entitlement): array
    {
        $entitlement->loadMissing(['product', 'user']);
        $status = match (true) {
            $entitlement->isRevoked() => 'revoked',
            $entitlement->isSuspended() => 'suspended',
            $entitlement->paused_at !== null => 'paused',
            ! $this->clock->active($entitlement) => 'expired',
            default => 'active',
        };

        return ['id' => $entitlement->id, 'product_id' => $entitlement->product_id,
            'lifetime' => $entitlement->is_lifetime, 'expires_at' => $entitlement->expires_at?->toIso8601String(),
            'paused_at' => $entitlement->paused_at?->toIso8601String(), 'remaining_seconds' => $entitlement->remaining_seconds,
            'product' => $entitlement->product->only(['id', 'name', 'slug']), 'status' => $status,
            'device' => ['scope' => config('licensekit.device_binding.scope'), 'bound' => $entitlement->binding?->hwid !== null, 'bound_at' => $entitlement->binding?->bound_at?->toIso8601String()],
            'reset' => $this->resets->for($entitlement)];
    }
}
