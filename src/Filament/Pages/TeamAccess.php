<?php

namespace LicenseKit\Filament\Pages;

class TeamAccess extends LicenseKitPage
{
    protected static ?string $slug = 'licensekit/team-access';

    protected static ?string $navigationParentItem = 'Settings';

    protected static ?string $navigationLabel = 'Team access';

    protected string $view = 'licensekit::team-access';
}
