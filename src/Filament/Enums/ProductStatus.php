<?php

namespace LicenseKit\Filament\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use LicenseKit\Core\Models\Product;

enum ProductStatus: string implements HasColor, HasDescription, HasIcon, HasLabel
{
    case Active = Product::ACTIVE;
    case Maintenance = Product::MAINTENANCE;
    case Discontinued = Product::DISCONTINUED;

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Active => 'On sale and working.',
            self::Maintenance => 'The software stops working for everyone. Licenses keep their time.',
            self::Discontinued => 'No new keys. Existing licenses keep working.',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Maintenance => 'warning',
            self::Discontinued => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Active => Heroicon::OutlinedCheckCircle,
            self::Maintenance => Heroicon::OutlinedWrenchScrewdriver,
            self::Discontinued => Heroicon::OutlinedArchiveBox,
        };
    }
}
