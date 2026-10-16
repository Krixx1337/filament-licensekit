<?php

namespace LicenseKit\Filament\Resources\ActivityLog\Pages;

use Filament\Resources\Pages\ManageRecords;
use LicenseKit\Filament\Concerns\DisplaysLicensingErrors;
use LicenseKit\Filament\Resources\ActivityLog\ActivityEntryResource;

class ManageActivityLog extends ManageRecords
{
    use DisplaysLicensingErrors;

    public function boot(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    protected static string $resource = ActivityEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
