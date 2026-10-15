<?php

namespace LicenseKit\Core\Actions;

use Carbon\CarbonImmutable;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Entitlement;

class EntitlementClock
{
    public function active(Entitlement $entitlement): bool
    {
        return $entitlement->paused_at === null && ($entitlement->is_lifetime || (bool) $entitlement->expires_at?->isFuture());
    }

    public function grant(Entitlement $entitlement, ?int $seconds): void
    {
        if ($entitlement->paused_at !== null) {
            throw new LicensingException('subscription_paused');
        }
        if ($seconds === null) {
            $entitlement->is_lifetime = true;
            $entitlement->expires_at = null;
        } else {
            $start = $entitlement->expires_at?->isFuture() ? $entitlement->expires_at : CarbonImmutable::now();
            $entitlement->expires_at = $start->startOfSecond()->addSeconds($seconds);
        }
        $entitlement->save();
    }

    public function extend(Entitlement $entitlement, int $seconds): void
    {
        if ($entitlement->paused_at !== null) {
            $entitlement->remaining_seconds += $seconds;
            $entitlement->save();
        } else {
            $this->grant($entitlement, $seconds);
        }
    }

    public function pause(Entitlement $entitlement): void
    {
        $at = CarbonImmutable::now('UTC')->startOfSecond();
        $remaining = $entitlement->expires_at === null ? 0 : (int) $at->diffInSeconds($entitlement->expires_at, false);
        if ($entitlement->paused_at !== null || $remaining <= 0) {
            throw new LicensingException('cannot_pause_entitlement', 409);
        }
        $entitlement->update(['paused_at' => $at, 'remaining_seconds' => $remaining, 'expires_at' => null]);
    }

    public function resume(Entitlement $entitlement): void
    {
        if ($entitlement->paused_at === null || $entitlement->remaining_seconds === null || $entitlement->remaining_seconds <= 0) {
            throw new LicensingException('cannot_resume_entitlement', 409);
        }
        $entitlement->update([
            'expires_at' => CarbonImmutable::now('UTC')->startOfSecond()->addSeconds($entitlement->remaining_seconds),
            'paused_at' => null,
            'remaining_seconds' => null,
        ]);
    }
}
