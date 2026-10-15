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
    protected static ?string $slug = 'licensekit/batches';

    protected static ?string $model = Batch::class;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('Batch')->formatStateUsing(fn ($state, Batch $record): string => 'Batch #'.$state.($record->label !== null ? ' · '.$record->label : ''))->sortable()->toggleable(),
            TextColumn::make('label')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('plan.name')->label('Plan')->searchable()->toggleable(),
            TextColumn::make('creator.'.config('licensekit.user.display_column'))->label('Created by')->searchable()->toggleable(),
            TextColumn::make('apiClient.name')->label('API client')->searchable()->toggleable(),
            TextColumn::make('reseller.'.config('licensekit.user.display_column'))->label('Reseller')->searchable()->toggleable(),
            TextColumn::make('issuer')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('reference')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('quantity')->label('Original quantity')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('claimed_count')->label('Claimed')->toggleable(),
            TextColumn::make('unclaimed_count')->label('Unclaimed')->toggleable(),
            TextColumn::make('revoked_count')->label('Revoked')->toggleable(),
            TextColumn::make('created_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('api_client_id')->label('API client')->relationship('apiClient', 'name')->searchable(),
            SelectFilter::make('reseller_user_id')->label('Reseller')->relationship('reseller', config('licensekit.user.display_column'))->searchable(),
        ])->recordActions([
            Action::make('keys')->label('View keys')->visible(fn (): bool => LicenseKeyResource::canViewAny())
                ->url(fn (Batch $record): string => LicenseKeyResource::getUrl('index', ['filters' => ['batch_id' => ['value' => $record->id]]])),
            LicensingActions::exportBatch(), LicensingActions::revokeBatch(),
        ])->defaultSort('id', 'desc');
    }

    /** @return Builder<Batch> */
    public static function getEloquentQuery(): Builder
    {
        return Batch::query()->with(['plan', 'creator', 'apiClient', 'reseller'])->withCount([
            'keys as claimed_count' => fn (Builder $query) => $query->whereNotNull('claimed_at'),
            'keys as unclaimed_count' => fn (Builder $query) => $query->whereNull('claimed_at')->whereNull('revoked_at'),
            'keys as revoked_count' => fn (Builder $query) => $query->whereNotNull('revoked_at'),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageBatches::route('/')];
    }
}
