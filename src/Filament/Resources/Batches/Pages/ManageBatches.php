<?php

namespace LicenseKit\Filament\Resources\Batches\Pages;

use Filament\Resources\Pages\ManageRecords;
use LicenseKit\Filament\Concerns\DisplaysLicensingErrors;
use LicenseKit\Filament\Resources\Batches\BatchResource;

class ManageBatches extends ManageRecords
{
    use DisplaysLicensingErrors;

    protected static string $resource = BatchResource::class;

    public function boot(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
