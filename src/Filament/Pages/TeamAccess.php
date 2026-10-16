<?php

namespace LicenseKit\Filament\Pages;

use LicenseKit\Filament\Navigation;

class TeamAccess extends LicenseKitPage
{
    protected static ?string $slug = 'licensekit/team-access';

    protected static string|\UnitEnum|null $navigationGroup = Navigation::SETUP;

    protected static ?int $navigationSort = 30;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Team access';

    protected string $view = 'licensekit::team-access';
}
