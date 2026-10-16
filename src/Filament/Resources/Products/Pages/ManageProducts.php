<?php

namespace LicenseKit\Filament\Resources\Products\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use LicenseKit\Core\Actions\ActivityLog;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Concerns\DisplaysLicensingErrors;
use LicenseKit\Filament\Concerns\ShowsResult;
use LicenseKit\Filament\Resources\Products\ProductResource;

class ManageProducts extends ManageRecords
{
    use DisplaysLicensingErrors;
    use ShowsResult;

    public function boot(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->databaseTransaction()->after(fn ($record) => app(ActivityLog::class)->record('user:'.AdminAccess::actor()->getKey(), 'product_created',
            $record, ['after' => $record->toArray()]))];
    }
}
