<?php

namespace LicenseKit\Filament\Resources\Entitlements\Pages;

use Filament\Resources\Pages\ManageRecords;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\Concerns\DisplaysLicensingErrors;
use LicenseKit\Filament\Resources\Entitlements\EntitlementResource;

class ManageEntitlements extends ManageRecords
{
    use DisplaysLicensingErrors;

    public function boot(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    protected static string $resource = EntitlementResource::class;

    protected function getHeaderActions(): array
    {
        return [LicensingActions::suspendCustomer()];
    }
}
