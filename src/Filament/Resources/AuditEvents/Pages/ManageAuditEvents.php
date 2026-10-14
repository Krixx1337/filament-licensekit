<?php

namespace LicenseKit\Filament\Resources\AuditEvents\Pages;

use Filament\Resources\Pages\ManageRecords;
use LicenseKit\Filament\Concerns\DisplaysLicensingErrors;
use LicenseKit\Filament\Resources\AuditEvents\AuditEventResource;

class ManageAuditEvents extends ManageRecords
{
    use DisplaysLicensingErrors;

    public function boot(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    protected static string $resource = AuditEventResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
