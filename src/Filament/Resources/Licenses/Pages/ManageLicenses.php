<?php

namespace LicenseKit\Filament\Resources\Licenses\Pages;

use Filament\Resources\Pages\ManageRecords;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\Concerns\DisplaysLicensingErrors;
use LicenseKit\Filament\Resources\Licenses\LicenseResource;

class ManageLicenses extends ManageRecords
{
    use DisplaysLicensingErrors;

    public function boot(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    protected static string $resource = LicenseResource::class;

    protected function getHeaderActions(): array
    {
        return [LicensingActions::suspendCustomer()];
    }
}
