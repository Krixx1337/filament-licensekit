<?php

namespace LicenseKit\Filament\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Models\LicenseKey;

enum KeyStatus: string implements HasColor, HasIcon, HasLabel
{
    case Unused = 'unused';
    case Redeemed = 'redeemed';
    case Cancelled = 'cancelled';

    public static function for(LicenseKey $key): self
    {
        return match (true) {
            $key->redeemed_at !== null => self::Redeemed,
            $key->cancelled_at !== null => self::Cancelled,
            default => self::Unused,
        };
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scope(Builder $query): Builder
    {
        return match ($this) {
            self::Unused => $query->whereNull('redeemed_at')->whereNull('cancelled_at'),
            self::Redeemed => $query->whereNotNull('redeemed_at'),
            self::Cancelled => $query->whereNull('redeemed_at')->whereNotNull('cancelled_at'),
        };
    }

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Unused => 'info',
            self::Redeemed => 'success',
            self::Cancelled => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Unused => Heroicon::OutlinedKey,
            self::Redeemed => Heroicon::OutlinedCheckCircle,
            self::Cancelled => Heroicon::OutlinedXCircle,
        };
    }
}
