<?php

namespace LicenseKit\Filament\Resources\Products\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use LicenseKit\Filament\Concerns\DisplaysLicensingErrors;
use LicenseKit\Filament\Concerns\ShowsResult;
use LicenseKit\Filament\Resources\Products\ProductResource;

class ListProducts extends ListRecords
{
    use DisplaysLicensingErrors;
    use ShowsResult;

    protected static string $resource = ProductResource::class;

    public function boot(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Create product')->icon(Heroicon::OutlinedPlus)];
    }
}
