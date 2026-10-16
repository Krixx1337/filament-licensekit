<?php

namespace LicenseKit;

use Filament\Contracts\Plugin;
use Filament\Panel;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Filament\Resources\ApiClients\ApiClientResource;
use LicenseKit\Filament\Resources\AuditEvents\AuditEventResource;
use LicenseKit\Filament\Resources\Batches\BatchResource;
use LicenseKit\Filament\Resources\Compensations\CompensationResource;
use LicenseKit\Filament\Resources\DeviceResetHistory\DeviceResetHistoryResource;
use LicenseKit\Filament\Resources\Entitlements\EntitlementResource;
use LicenseKit\Filament\Resources\LicenseKeys\LicenseKeyResource;
use LicenseKit\Filament\Resources\LicensePlans\LicensePlanResource;
use LicenseKit\Filament\Resources\LicensingBans\LicensingBanResource;
use LicenseKit\Filament\Resources\Outages\OutageResource;
use LicenseKit\Filament\Resources\Products\ProductResource;
use LicenseKit\Support\Modules;
use LicenseKit\Support\SetupDiagnostics;

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

    public function hasOutages(): bool
    {
        return Modules::enabled('outages');
    }

    public function register(Panel $panel): void
    {
        $diagnostics = app(SetupDiagnostics::class);
        if ($diagnostics->configurationErrors() !== [] || $diagnostics->panelError($panel) !== null) {
            return;
        }
        $resources = [ProductResource::class, LicensePlanResource::class, LicenseKeyResource::class, BatchResource::class, EntitlementResource::class, ApiClientResource::class, DeviceResetHistoryResource::class, AuditEventResource::class];
        if (config('licensekit.features.licensing_bans') === true) {
            $resources[] = LicensingBanResource::class;
        }
        if ($this->hasOutages()) {
            $resources[] = OutageResource::class;
            $resources[] = CompensationResource::class;
        }
        $panel->resources($resources);
    }

    public function boot(Panel $panel): void
    {
        $diagnostics = app(SetupDiagnostics::class);
        $diagnostics->assertConfigured();
        if ($diagnostics->panelError($panel) !== null) {
            throw new LicensingException('licensing_unavailable', 503);
        }
    }
}
