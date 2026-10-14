<?php

namespace LicenseKit\Filament\Resources\Outages\Pages;

use Filament\Resources\Pages\ManageRecords;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\Concerns\DisplaysLicensingErrors;
use LicenseKit\Filament\Resources\Outages\OutageResource;

class ManageOutages extends ManageRecords
{
    use DisplaysLicensingErrors;

    public function boot(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    protected static string $resource = OutageResource::class;

    protected function getHeaderActions(): array
    {
        return [LicensingActions::startOutage()];
    }
}
