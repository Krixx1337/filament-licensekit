<?php

namespace LicenseKit\Filament\Pages;

use Filament\Pages\Page;
use LicenseKit\Filament\AdminAccess;

abstract class LicenseKitPage extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Licensing';

    public static function canAccess(): bool
    {
        return AdminAccess::isSupport();
    }
}
