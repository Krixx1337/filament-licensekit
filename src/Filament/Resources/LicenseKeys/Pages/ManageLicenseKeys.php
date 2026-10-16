<?php

namespace LicenseKit\Filament\Resources\LicenseKeys\Pages;

use Filament\Resources\Pages\ManageRecords;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\Concerns\DisplaysLicensingErrors;
use LicenseKit\Filament\Concerns\ShowsResult;
use LicenseKit\Filament\Resources\LicenseKeys\LicenseKeyResource;

class ManageLicenseKeys extends ManageRecords
{
    use DisplaysLicensingErrors;
    use ShowsResult;

    public function boot(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    protected static string $resource = LicenseKeyResource::class;

    protected function getHeaderActions(): array
    {
        return [LicensingActions::createKeys()];
    }
}
