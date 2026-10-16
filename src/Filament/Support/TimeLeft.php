<?php

namespace LicenseKit\Filament\Support;

use Carbon\CarbonInterface;
use LicenseKit\Core\Models\License;

/** Plain-language time remaining for a license, shared by tables, the license page and the dashboard. */
final class TimeLeft
{
    public static function for(License $license): string
    {
        return match (true) {
            $license->isRevoked() => 'Cancelled',
            $license->is_lifetime => 'Never expires',
            $license->paused_at !== null => 'Paused · '.self::duration((int) $license->remaining_seconds).' saved',
            $license->expires_at === null => $license->product->freeAccessActive() ? 'Free until '.self::date($license->product->free_until) : 'No time yet',
            $license->expires_at->isFuture() => self::duration((int) now()->diffInSeconds($license->expires_at)).' left',
            default => 'Expired '.$license->expires_at->diffForHumans(),
        };
    }

    /** The largest unit, rounded: "30 days", "5 hours", "12 minutes". */
    public static function duration(int $seconds): string
    {
        [$amount, $unit] = match (true) {
            $seconds >= 86400 => [(int) round($seconds / 86400), 'day'],
            $seconds >= 3600 => [(int) round($seconds / 3600), 'hour'],
            default => [max(1, (int) round($seconds / 60)), 'minute'],
        };

        return $amount.' '.$unit.($amount === 1 ? '' : 's');
    }

    /** A date in the app's timezone, for example "Fri 17 Oct, 18:00". */
    public static function date(?CarbonInterface $moment): string
    {
        return $moment === null ? '' : $moment->setTimezone(config('app.timezone'))->format('D j M, H:i');
    }
}
