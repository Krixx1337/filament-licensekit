<?php

namespace LicenseKit\Filament\Resources\LicensePlans;

use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Actions\LicenseDuration;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\CatalogWrites;
use LicenseKit\Filament\Resources\LicensePlans\Pages\ManageLicensePlans;
use LicenseKit\Filament\Resources\LicensingResource;

class LicensePlanResource extends LicensingResource
{
    protected static ?string $slug = 'licensekit/license-plans';

    protected static ?string $model = LicensePlan::class;

    protected static ?string $navigationLabel = 'Plans';

    protected static ?string $modelLabel = 'plan';

    protected static ?string $pluralModelLabel = 'Plans';

    protected static bool $editable = true;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Select::make('product_id')->relationship('product', 'name')->searchable()->required()->disabledOn('edit'),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('duration_days')->label('Duration (days)')->integer()->minValue(1)->maxValue(LicenseDuration::MAXIMUM_DAYS)->helperText('Leave empty for lifetime access.'),
            Toggle::make('is_enabled')->default(true)]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('product.name')->searchable()->toggleable(),
            TextColumn::make('product.group.name')->label('Product group')->placeholder('Ungrouped')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('name')->searchable()->toggleable(),
            TextColumn::make('duration_days')->label('Duration (days)')->placeholder('Lifetime')->toggleable(),
            TextColumn::make('unclaimed_count')->label('Keys left')->toggleable()])->filters([
                SelectFilter::make('group')->label('Product group')->relationship('product.group', 'name', hasEmptyOption: true)
                    ->emptyRelationshipOptionLabel('Ungrouped')->searchable()->preload(),
            ])->recordActions([EditAction::make()->using(fn (Model $record, array $data) => CatalogWrites::update($record, $data)),
                LicensingActions::createKeys()])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageLicensePlans::route('/')];
    }

    /** @return Builder<LicensePlan> */
    public static function getEloquentQuery(): Builder
    {
        return LicensePlan::query()->with(['product.group'])->withCount([
            'keys as unclaimed_count' => fn (Builder $query) => $query->whereNull('claimed_at')->whereNull('revoked_at'),
        ]);
    }
}
