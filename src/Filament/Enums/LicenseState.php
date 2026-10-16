<?php

namespace LicenseKit\Filament\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use LicenseKit\Core\Models\License;

/** What a seller sees for a license, in the order the checks apply. */
enum LicenseState: string implements HasColor, HasIcon, HasLabel
{
    case Cancelled = 'cancelled';
    case Suspended = 'suspended';
    case Paused = 'paused';
    case Lifetime = 'lifetime';
    case FreeAccess = 'free_access';
    case ExpiringSoon = 'expiring';
    case Active = 'active';
    case NoTimeYet = 'no_time';
    case Expired = 'expired';

    public const EXPIRING_DAYS = 7;

    public static function for(License $license): self
    {
        return match (true) {
            $license->isRevoked() => self::Cancelled,
            $license->isSuspended() => self::Suspended,
            $license->paused_at !== null => self::Paused,
            $license->is_lifetime => self::Lifetime,
            $license->product->freeAccessActive() && ! (bool) $license->expires_at?->isFuture() => self::FreeAccess,
            (bool) $license->expires_at?->isFuture() && $license->expires_at->lessThanOrEqualTo(now()->addDays(self::EXPIRING_DAYS)) => self::ExpiringSoon,
            (bool) $license->expires_at?->isFuture() => self::Active,
            $license->expires_at === null => self::NoTimeYet,
            default => self::Expired,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Cancelled => 'Cancelled',
            self::Suspended => 'Suspended',
            self::Paused => 'Paused',
            self::Lifetime => 'Lifetime',
            self::FreeAccess => 'Free access',
            self::ExpiringSoon => 'Expiring soon',
            self::Active => 'Active',
            self::NoTimeYet => 'No time yet',
            self::Expired => 'Expired',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Cancelled, self::Suspended => 'danger',
            self::Paused, self::ExpiringSoon => 'warning',
            self::Lifetime, self::Active => 'success',
            self::FreeAccess => 'info',
            self::NoTimeYet, self::Expired => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Cancelled => Heroicon::OutlinedXCircle,
            self::Suspended => Heroicon::OutlinedNoSymbol,
            self::Paused => Heroicon::OutlinedPauseCircle,
            self::Lifetime => Heroicon::OutlinedStar,
            self::FreeAccess => Heroicon::OutlinedGift,
            self::ExpiringSoon => Heroicon::OutlinedClock,
            self::Active => Heroicon::OutlinedCheckCircle,
            self::NoTimeYet => Heroicon::OutlinedMinusCircle,
            self::Expired => Heroicon::OutlinedCalendar,
        };
    }
}
