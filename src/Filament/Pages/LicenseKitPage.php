<?php

namespace LicenseKit\Filament\Pages;

use Filament\Pages\Page;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Navigation;

abstract class LicenseKitPage extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = Navigation::LICENSING;

    public static function canAccess(): bool
    {
        // Setup, connection and team pages cover every product.
        return AdminAccess::isSupport() && ! AdminAccess::isScoped();
    }
}
