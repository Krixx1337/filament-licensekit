<?php

namespace LicenseKit\Filament\Resources\LicensePlans\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use LicenseKit\Filament\CatalogWrites;
use LicenseKit\Filament\Concerns\DisplaysLicensingErrors;
use LicenseKit\Filament\Resources\LicensePlans\LicensePlanResource;

class ManageLicensePlans extends ManageRecords
{
    use DisplaysLicensingErrors;

    public function boot(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    protected static string $resource = LicensePlanResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->using(fn (array $data) => CatalogWrites::create(static::getModel(), $data))];
    }
}
