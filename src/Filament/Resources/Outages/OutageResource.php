<?php

namespace LicenseKit\Filament\Resources\Outages;

use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Resources\LicensingResource;
use LicenseKit\Filament\Resources\Outages\Pages\ManageOutages;
use LicenseKit\Modules\Outages\Models\Outage;

class OutageResource extends LicensingResource
{
    protected static ?string $slug = 'licensekit/outages';

    protected static ?string $model = Outage::class;

    protected static ?string $navigationLabel = 'Service down';

    protected static ?string $modelLabel = 'service down';

    protected static ?string $pluralModelLabel = 'Service down';

    protected static bool $editable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('reference')->searchable()->toggleable(isToggledHiddenByDefault: true), TextColumn::make('product.name')->label('Product')->toggleable(),
            TextColumn::make('reason')->limit(50)->toggleable(), IconColumn::make('access_blocked')->label('Blocks access')->boolean()->toggleable(),
            TextColumn::make('operation.status')->label('Credit status')->badge()->toggleable(isToggledHiddenByDefault: true), TextColumn::make('operation.failure')->label('Credit error')->placeholder('None')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('operation.target_count')->label('Targets')->toggleable(isToggledHiddenByDefault: true), TextColumn::make('operation.credited_count')->label('Credited')->toggleable(isToggledHiddenByDefault: true), TextColumn::make('operation.skipped_count')->label('Skipped')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('started_at')->label('Started')->dateTime()->toggleable(), TextColumn::make('stopped_at')->label('Stopped')->dateTime()->toggleable(), TextColumn::make('ended_at')->label('Ended')->dateTime()->toggleable(isToggledHiddenByDefault: true)])
            ->recordActions([LicensingActions::stopOutage(),
                Action::make('retry')->authorize(fn (Outage $record): bool => AdminAccess::can('retry', $record->operation, static::class))->visible(fn (Outage $record): bool => in_array($record->operation->status, ['pending', 'failed'], true))->requiresConfirmation()
                    ->action(fn (Outage $record) => app(\LicenseKit\Modules\Outages\Actions\ManageOutages::class)->retry($record->operation, AdminAccess::actor())),
                Action::make('cancel')->authorize(fn (Outage $record): bool => AdminAccess::can('cancel', $record->operation, static::class))->visible(fn (Outage $record): bool => in_array($record->operation->status, ['pending', 'failed'], true))->requiresConfirmation()
                    ->action(fn (Outage $record) => app(\LicenseKit\Modules\Outages\Actions\ManageOutages::class)->cancel($record->operation, AdminAccess::actor())),
            ])->emptyStateHeading('Nothing is down')->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageOutages::route('/')];
    }
}
