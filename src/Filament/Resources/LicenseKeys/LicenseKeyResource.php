<?php

namespace LicenseKit\Filament\Resources\LicenseKeys;

use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use LicenseKit\Core\Actions\SerialKey;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Resources\LicenseKeys\Pages\ManageLicenseKeys;
use LicenseKit\Filament\Resources\Licenses\LicenseResource;
use LicenseKit\Filament\Resources\LicensingResource;

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
        return $table->columns([TextColumn::make('key')->searchable(query: fn ($query, string $search) => $query->where('key', 'like', '%'.strtoupper(app(SerialKey::class)
            ->searchTerm($search)).'%'))->copyable()->toggleable(),
            TextColumn::make('batch.plan.name')->label('Plan')->searchable()->toggleable(),
            TextColumn::make('batch_id')->label('Batch')->formatStateUsing(fn ($state, LicenseKey $record): string => 'Batch #'.$state.($record->batch
                ->label !== null ? ' · '.$record->batch->label : ''))->toggleable(),
            TextColumn::make('batch.label')->label('Batch label')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('note')->searchable()->limit(30)->wrap()->toggleable(),
            TextColumn::make('license.user.'.config('licensekit.user_label'))->label('Redeemed by')->searchable()->url(fn (LicenseKey $record) => $record
                ->license_id === null ? null : LicenseResource::getUrl('view', ['record' => $record->license_id])),
            TextColumn::make('redeemed_at')->label('Redeemed at')->since()->dateTimeTooltip()->toggleable(),
            TextColumn::make('status')->label('Status')->state(fn (LicenseKey $record) => $record->redeemed_at !== null ? 'Redeemed' : ($record
                ->cancelled_at !== null ? 'Cancelled' : 'Unused'))->badge()->toggleable()])->filters([
                    SelectFilter::make('product_id')->label('Product')->options(fn () => Product::pluck('name', 'id'))
                        ->query(fn ($query, array $data) => $query->when($data['value'] ?? null, fn ($q, $id) => $q->whereHas('batch.plan', fn ($q) => $q->where('product_id', $id)))),
                    SelectFilter::make('status')->options(['unused' => 'Unused', 'redeemed' => 'Redeemed', 'cancelled' => 'Cancelled'])
                        ->query(function ($query, array $data) {
                            return match ($data['value'] ?? '') {
                                'unused' => $query->whereNull('redeemed_at')->whereNull('cancelled_at'), 'redeemed' => $query->whereNotNull('redeemed_at'), 'cancelled' => $query
                                    ->whereNotNull('cancelled_at'), default => $query
                            };
                        }),
                    SelectFilter::make('batch_id')->label('Batch')->relationship('batch', 'id')->getOptionLabelFromRecordUsing(fn (Batch $record): string => 'Batch #'.$record
                        ->id.($record->label !== null ? ' · '.$record->label : ''))->searchable(),
                ])->recordActions([LicensingActions::claim(), LicensingActions::editNote(),
                    LicensingActions::revokeKey()])->defaultSort('id', 'desc');
    }

    /** @return Builder<LicenseKey> */
    public static function getEloquentQuery(): Builder
    {
        return LicenseKey::query()->with(['batch.plan', 'license.user']);
    }

    public static function getPages(): array
    {
        return ['index' => ManageLicenseKeys::route('/')];
    }
}
