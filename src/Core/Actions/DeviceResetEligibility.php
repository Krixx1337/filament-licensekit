<?php

namespace LicenseKit\Core\Actions;

use LicenseKit\Core\Models\Entitlement;

class DeviceResetEligibility
{
    public function __construct(private DeviceBindings $bindings) {}

    /** @return array{allowed: bool, available_at: string|null, reason: string|null} */
    public function for(Entitlement $entitlement): array
    {
        return $this->bindings->eligibility($entitlement->binding);
    }
}
