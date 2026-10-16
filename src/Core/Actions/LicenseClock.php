<?php

namespace LicenseKit\Core\Actions;

use Carbon\CarbonImmutable;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\License;

class LicenseClock
{
    public function active(License $license): bool
    {
        return $license->paused_at === null && ($license->is_lifetime || (bool) $license->expires_at?->isFuture());
    }

    public function grant(License $license, ?int $seconds): void
    {
        if ($license->paused_at !== null) {
            throw new LicensingException('subscription_paused');
        }
        if ($seconds === null) {
            $license->is_lifetime = true;
            $license->expires_at = null;
        } else {
            $start = $license->expires_at?->isFuture() ? $license->expires_at : CarbonImmutable::now();
            $license->expires_at = $start->startOfSecond()->addSeconds($seconds);
        }
        $license->save();
    }

    public function extend(License $license, int $seconds): void
    {
        if ($license->paused_at !== null) {
            $license->remaining_seconds += $seconds;
            $license->save();
        } else {
            $this->grant($license, $seconds);
        }
    }

    public function pause(License $license): void
    {
        $at = CarbonImmutable::now('UTC')->startOfSecond();
        $remaining = $license->expires_at === null ? 0 : (int) $at->diffInSeconds($license->expires_at, false);
        if ($license->paused_at !== null || $remaining <= 0) {
            throw new LicensingException('cannot_pause_license', 409);
        }
        $license->update(['status' => License::PAUSED, 'paused_at' => $at, 'remaining_seconds' => $remaining, 'expires_at' => null]);
    }

    public function resume(License $license): void
    {
        if ($license->paused_at === null || $license->remaining_seconds === null || $license->remaining_seconds <= 0) {
            throw new LicensingException('cannot_resume_license', 409);
        }
        $license->update([
            'expires_at' => CarbonImmutable::now('UTC')->startOfSecond()->addSeconds($license->remaining_seconds),
            'status' => License::ACTIVE,
            'paused_at' => null,
            'remaining_seconds' => null,
        ]);
    }
}
