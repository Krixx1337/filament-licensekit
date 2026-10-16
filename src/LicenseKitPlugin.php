<?php

namespace LicenseKit;

use Filament\Contracts\Plugin;
use Filament\Panel;
use LicenseKit\Filament\Middleware\EnsurePanelSetup;
use LicenseKit\Filament\Navigation;
use LicenseKit\Filament\Pages\ConnectYourApp;
use LicenseKit\Filament\Pages\Dashboard;
use LicenseKit\Filament\Pages\SetupNeeded;
use LicenseKit\Filament\Pages\TeamAccess;
use LicenseKit\Filament\Resources\ActivityLog\ActivityEntryResource;
use LicenseKit\Filament\Resources\ApiConnections\ApiConnectionResource;
use LicenseKit\Filament\Resources\Batches\BatchResource;
use LicenseKit\Filament\Resources\DeviceBans\DeviceBanResource;
use LicenseKit\Filament\Resources\LicenseKeys\LicenseKeyResource;
use LicenseKit\Filament\Resources\Licenses\LicenseResource;
use LicenseKit\Filament\Resources\Products\ProductResource;

final class LicenseKitPlugin implements Plugin
{
    public static function make(): static
    {
        return new self;
    }

    public function getId(): string
    {
        return 'licensekit';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([ProductResource::class, LicenseKeyResource::class, BatchResource::class, LicenseResource::class,
            ApiConnectionResource::class, ActivityEntryResource::class, DeviceBanResource::class]);
        $panel->pages([Dashboard::class, ConnectYourApp::class, SetupNeeded::class, TeamAccess::class]);
        if ($panel->getNavigationGroups() === []) {
            // Keep the LicenseKit groups in a sensible order unless the host already orders its groups.
            $panel->navigationGroups([Navigation::LICENSING, Navigation::PROTECTION, Navigation::SETUP]);
        }
        $panel->authMiddleware([EnsurePanelSetup::class], isPersistent: true);
    }

    public function boot(Panel $panel): void {}
}
