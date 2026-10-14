<?php

namespace LicenseKit\Filament\Resources\Compensations\Pages;

use Filament\Resources\Pages\ManageRecords;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\Concerns\DisplaysLicensingErrors;
use LicenseKit\Filament\Resources\Compensations\CompensationResource;

class ManageCompensations extends ManageRecords
{
    use DisplaysLicensingErrors;

    protected static string $resource = CompensationResource::class;

    public function boot(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [LicensingActions::addTime()];
    }
}
