<?php

namespace LicenseKit\Filament\Resources\ProductGroups\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use LicenseKit\Filament\CatalogWrites;
use LicenseKit\Filament\Concerns\DisplaysLicensingErrors;
use LicenseKit\Filament\Resources\ProductGroups\ProductGroupResource;

class ManageProductGroups extends ManageRecords
{
    use DisplaysLicensingErrors;

    protected static string $resource = ProductGroupResource::class;

    public function boot(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->using(fn (array $data) => CatalogWrites::create(static::getModel(), $data))];
    }
}
