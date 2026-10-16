<?php

namespace LicenseKit\Filament\Pages;

class Settings extends LicenseKitPage
{
    protected static ?string $slug = 'licensekit/settings';

    protected static ?string $navigationLabel = 'Settings';

    protected static ?int $navigationSort = 50;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected string $view = 'licensekit::settings';
}
