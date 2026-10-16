<?php

namespace LicenseKit\Filament\Resources\LicenseKeys;

use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use LicenseKit\Core\Actions\SerialKey;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Enums\KeyStatus;
use LicenseKit\Filament\Resources\LicenseKeys\Pages\ManageLicenseKeys;
use LicenseKit\Filament\Resources\Licenses\LicenseResource;
use LicenseKit\Filament\Resources\LicensingResource;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LicenseKeyResource extends LicensingResource
{
    protected static ?int $navigationSort = 30;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static ?string $slug = 'licensekit/license-keys';

    protected static ?string $model = LicenseKey::class;

    protected static string $access = AdminAccess::SUPPORT;

    protected static ?string $navigationLabel = 'Keys';

    protected static ?string $modelLabel = 'key';

    protected static ?string $pluralModelLabel = 'Keys';

    protected static bool $editable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('key')->fontFamily(FontFamily::Mono)->weight(FontWeight::Medium)->copyable()->copyMessage('Key copied')
                ->description(fn (LicenseKey $record): ?string => $record->note)
                ->searchable(query: fn ($query, string $search) => $query->where(fn ($q) => $q
                    ->where('key', 'like', '%'.strtoupper(app(SerialKey::class)->searchTerm($search)).'%')->orWhere('note', 'like', '%'.$search.'%'))),
            TextColumn::make('batch.plan.product.name')->label('Product')->description(fn (LicenseKey $record): string => $record->batch->plan->name)->searchable(),
            TextColumn::make('status')->label('Status')->badge()->state(fn (LicenseKey $record): KeyStatus => KeyStatus::for($record)),
            TextColumn::make('license.user.'.config('licensekit.user_label'))->label('Redeemed by')->placeholder('Not redeemed')->searchable()
                ->description(fn (LicenseKey $record): ?string => $record->redeemed_at?->diffForHumans())
                ->url(fn (LicenseKey $record) => $record->license_id === null ? null : LicenseResource::getUrl('view', ['record' => $record->license_id])),
            TextColumn::make('batch_id')->label('Batch')->formatStateUsing(fn ($state, LicenseKey $record): string => $record->batch->label ?? 'Batch #'.$state)
                ->toggleable()->visibleFrom('lg'),
            TextColumn::make('created_at')->label('Created')->since()->dateTimeTooltip(timezone: config('app.timezone'))->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('product_id')->label('Product')->options(fn () => Product::pluck('name', 'id'))
                ->query(fn ($query, array $data) => $query->when($data['value'] ?? null, fn ($q, $id) => $q->whereHas('batch.plan', fn ($q) => $q->where('product_id', $id)))),
            SelectFilter::make('batch_id')->label('Batch')->relationship('batch', 'id')->searchable()
                ->getOptionLabelFromRecordUsing(fn (Batch $record): string => 'Batch #'.$record->id.($record->label !== null ? ' · '.$record->label : '')),
        ])->recordActions([
            ActionGroup::make([LicensingActions::claim(), LicensingActions::editNote(), LicensingActions::revokeKey()]),
        ])->toolbarActions([
            BulkAction::make('download_selected')->label('Download selected')->icon(Heroicon::OutlinedArrowDownTray)->authorize(fn (): bool => AdminAccess::isAdmin())
                ->action(fn (Collection $records): StreamedResponse => response()->streamDownload(function () use ($records): void {
                    echo $records->sortBy('id')->pluck('key')->implode("\r\n");
                }, 'license-keys.txt', ['Cache-Control' => 'private, no-store']))->deselectRecordsAfterCompletion(),
        ])->emptyStateHeading('No keys yet')->emptyStateIcon('heroicon-o-key')
            ->emptyStateDescription('Generate keys to sell or give away. Customers redeem a key on your website or in your app.')
            ->persistFiltersInSession()->persistSearchInSession()->defaultSort('id', 'desc');
    }

    /** @return Builder<LicenseKey> */
    public static function getEloquentQuery(): Builder
    {
        return LicenseKey::query()->with(['batch.plan.product', 'license.user']);
    }

    public static function getPages(): array
    {
        return ['index' => ManageLicenseKeys::route('/')];
    }
}
