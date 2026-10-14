<?php

namespace LicenseKit\Filament\Resources\DeviceResetHistory\Pages;

use Filament\Resources\Pages\ManageRecords;
use Filament\Tables\Table;
use LicenseKit\Filament\Resources\DeviceResetHistory\DeviceResetHistoryResource;

class ManageDeviceResetHistory extends ManageRecords
{
    protected static string $resource = DeviceResetHistoryResource::class;

    public function boot(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    public function table(Table $table): Table
    {
        return $table->emptyStateHeading('No resets yet');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
