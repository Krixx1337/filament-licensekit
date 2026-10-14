<?php

namespace LicenseKit\Core\Actions;

use Carbon\CarbonImmutable;
use LicenseKit\Core\Models\Entitlement;

class EntitlementClock
{
    public function active(Entitlement $entitlement): bool
    {
        return $entitlement->is_lifetime || (bool) $entitlement->expires_at?->isFuture();
    }

    public function grant(Entitlement $entitlement, ?int $seconds): void
    {
        if ($seconds === null) {
            $entitlement->is_lifetime = true;
            $entitlement->expires_at = null;
        } else {
            $start = $entitlement->expires_at?->isFuture() ? $entitlement->expires_at : CarbonImmutable::now();
            $entitlement->expires_at = $start->startOfSecond()->addSeconds($seconds);
        }
        $entitlement->save();
    }
}
