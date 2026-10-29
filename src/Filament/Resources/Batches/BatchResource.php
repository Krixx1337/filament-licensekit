<?php

namespace LicenseKit\Filament\Resources\Batches;

use Filament\Actions\ActionGroup;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\AdminAccess;
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
            TextColumn::make('id')->label('Batch')->weight(FontWeight::Medium)->sortable()
                ->formatStateUsing(fn ($state, Batch $record): string => $record->label ?? 'Batch #'.$state)
                ->description(fn (Batch $record): string => 'Batch #'.$record->id.' · by '.$record->created_by.', '.$record->created_at?->diffForHumans())
                ->searchable(['label', 'reference']),
            TextColumn::make('plan.product.name')->label('Product')->description(fn (Batch $record): string => $record->plan->name),
            TextColumn::make('redeemed_count')->label('Redeemed')
                ->formatStateUsing(fn ($state, Batch $record): string => $state.' of '.$record->quantity)
                ->description(fn (Batch $record): string => $record->unused_count.' unused'.($record->cancelled_count > 0 ? ', '.$record->cancelled_count.' cancelled' : '')),
            TextColumn::make('source')->label('Made by')->badge()->color('gray')
                ->formatStateUsing(fn (string $state, Batch $record): string => match ($state) {
                    'api' => 'Store: '.($record->apiConnection->name ?? 'deleted connection'),
                    'admin' => 'Admin panel',
                    default => 'Your app',
                }),
            TextColumn::make('reseller.'.config('licensekit.user_label'))->label('Reseller')->placeholder('Your shop'),
            TextColumn::make('reference')->label('Order reference')->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('reseller_id')->label('Reseller')->relationship('reseller', config('licensekit.user_label'))->searchable(),
            SelectFilter::make('api_connection_id')->label('Store connection')->relationship('apiConnection', 'name')->searchable(),
        ])->recordActions([
            ActionGroup::make([LicensingActions::exportBatch(), LicensingActions::revokeBatch()]),
        ])->recordUrl(fn (Batch $record): ?string => LicenseKeyResource::canViewAny()
            ? LicenseKeyResource::getUrl('index', ['filters' => ['batch_id' => ['value' => $record->id]]]) : null)
            ->emptyStateHeading('No key batches yet')->emptyStateIcon('heroicon-o-rectangle-stack')
            ->emptyStateDescription('Every time you or your store generate keys, they are grouped in a batch here.')
            ->defaultSort('id', 'desc');
    }

    /** @return Builder<Batch> */
    public static function getEloquentQuery(): Builder
    {
        return AdminAccess::scope(Batch::query(), through: 'plan')->with(['plan.product', 'apiConnection', 'reseller'])->withCount([
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
