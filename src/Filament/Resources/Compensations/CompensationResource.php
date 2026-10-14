<?php

namespace LicenseKit\Filament\Resources\Compensations;

use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Resources\LicensingResource;
use LicenseKit\Modules\Outages\Actions\ManageOutages;
use LicenseKit\Modules\Outages\Models\LicensingOperation;

class CompensationResource extends LicensingResource
{
    protected static ?string $slug = 'licensekit/compensations';

    protected static ?string $model = LicensingOperation::class;

    protected static ?string $navigationLabel = 'Added time';

    protected static ?string $modelLabel = 'added time';

    protected static ?string $pluralModelLabel = 'Added time';

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('reference')->searchable()->toggleable(isToggledHiddenByDefault: true), TextColumn::make('product.name')->label('Product')->toggleable(),
            TextColumn::make('kind')->badge()->toggleable(), TextColumn::make('status')->badge()->toggleable(), TextColumn::make('failure')->label('Error')->placeholder('None')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('seconds')->label('Seconds added')->toggleable(), TextColumn::make('eligible_at')->label('Count from')->dateTime()->toggleable(isToggledHiddenByDefault: true), TextColumn::make('target_count')->label('Targets')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('credited_count')->label('Credited')->toggleable(), TextColumn::make('skipped_count')->label('Skipped')->toggleable(isToggledHiddenByDefault: true), TextColumn::make('applied_at')->label('Applied')->dateTime()->toggleable(isToggledHiddenByDefault: true)])
            ->recordActions([
                Action::make('retry')->authorize(fn (LicensingOperation $record): bool => AdminAccess::can('retry', $record, static::class))->requiresConfirmation()
                    ->visible(fn (LicensingOperation $record): bool => in_array($record->status, ['pending', 'failed'], true))
                    ->action(fn (LicensingOperation $record) => app(ManageOutages::class)->retry($record, AdminAccess::actor())),
                Action::make('cancel')->authorize(fn (LicensingOperation $record): bool => AdminAccess::can('cancel', $record, static::class))->requiresConfirmation()
                    ->visible(fn (LicensingOperation $record): bool => in_array($record->status, ['pending', 'failed'], true))
                    ->action(fn (LicensingOperation $record) => app(ManageOutages::class)->cancel($record, AdminAccess::actor())),
            ])->emptyStateHeading('No time added yet')->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageCompensations::route('/')];
    }
}
