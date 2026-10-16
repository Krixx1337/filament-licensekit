<?php

namespace LicenseKit\Filament\Resources\Batches;

use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\Resources\Batches\Pages\ManageBatches;
use LicenseKit\Filament\Resources\LicenseKeys\LicenseKeyResource;
use LicenseKit\Filament\Resources\LicensingResource;

class BatchResource extends LicensingResource
{
    protected static ?int $navigationSort = 35;

    protected static ?string $navigationLabel = 'Key batches';

    protected static ?string $modelLabel = 'key batch';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $slug = 'licensekit/batches';

    protected static ?string $model = Batch::class;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('Batch')->formatStateUsing(fn ($state, Batch $record): string => 'Batch #'.$state.($record->label !== null ? ' · '.$record->label : ''))
                ->sortable()->toggleable(),
            TextColumn::make('label')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('plan.name')->label('Plan')->searchable()->toggleable(),
            TextColumn::make('created_by')->label('Created by')->searchable()->toggleable(),
            TextColumn::make('apiConnection.name')->label('Store connection')->searchable()->toggleable(),
            TextColumn::make('source')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('reference')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('quantity')->label('Original quantity')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('redeemed_count')->label('Redeemed')->toggleable(),
            TextColumn::make('unused_count')->label('Unused')->toggleable(),
            TextColumn::make('cancelled_count')->label('Cancelled')->toggleable(),
            TextColumn::make('created_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('api_connection_id')->label('Store connection')->relationship('apiConnection', 'name')->searchable(),
        ])->recordActions([
            Action::make('keys')->label('View keys')->visible(fn (): bool => LicenseKeyResource::canViewAny())
                ->url(fn (Batch $record): string => LicenseKeyResource::getUrl('index', ['filters' => ['batch_id' => ['value' => $record->id]]])),
            LicensingActions::exportBatch(), LicensingActions::revokeBatch(),
        ])->defaultSort('id', 'desc');
    }

    /** @return Builder<Batch> */
    public static function getEloquentQuery(): Builder
    {
        return Batch::query()->with(['plan', 'apiConnection'])->withCount([
            'keys as redeemed_count' => fn (Builder $query) => $query->whereNotNull('redeemed_at'),
            'keys as unused_count' => fn (Builder $query) => $query->whereNull('redeemed_at')->whereNull('cancelled_at'),
            'keys as cancelled_count' => fn (Builder $query) => $query->whereNotNull('cancelled_at'),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageBatches::route('/')];
    }
}
