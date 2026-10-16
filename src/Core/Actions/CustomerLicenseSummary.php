<?php

namespace LicenseKit\Core\Actions;

use LicenseKit\Core\Models\License;

class CustomerLicenseSummary
{
    public function __construct(private LicenseClock $clock, private LicensedDevices $devices) {}

    /** @return array<string, mixed> */
    public function for(License $license): array
    {
        $license->loadMissing(['product', 'user']);
        $status = match (true) {
            $license->isRevoked() => 'revoked',
            $license->isSuspended() => 'suspended',
            $license->paused_at !== null => 'paused',
            ! $this->clock->active($license) => 'expired',
            default => 'active',
        };

        return ['id' => $license->id, 'product_id' => $license->product_id,
            'lifetime' => $license->is_lifetime, 'expires_at' => $license->expires_at?->toIso8601String(),
            'paused_at' => $license->paused_at?->toIso8601String(), 'remaining_seconds' => $license->remaining_seconds,
            'product' => $license->product->only(['id', 'name', 'slug']), 'status' => $status,
            'device' => ['bound' => $license->device !== null, 'bound_at' => $license->device_bound_at?->toIso8601String()],
            'reset' => $this->devices->eligibility($license)];
    }
}
