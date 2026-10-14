<?php

namespace LicenseKit\Filament\Resources\Products;

use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\CatalogWrites;
use LicenseKit\Filament\Resources\LicensingResource;
use LicenseKit\Filament\Resources\Products\Pages\ManageProducts;

class ProductResource extends LicensingResource
{
    protected static ?string $slug = 'licensekit/products';

    protected static ?string $model = Product::class;

    protected static bool $editable = true;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->required()->maxLength(255),
            Select::make('product_group_id')->label('Product group')->relationship('group', 'name')->searchable()->preload()->nullable()->exists('licensekit_product_groups', 'id'),
            TextInput::make('slug')->required()->maxLength(100)->regex('/^[a-z0-9-]+$/')->unique(ignoreRecord: true),
            Toggle::make('is_enabled')->default(true),
            Toggle::make('is_blocked')->label('Manual maintenance block'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable()->toggleable(),
            TextColumn::make('group.name')->label('Product group')->placeholder('Ungrouped')->searchable()->toggleable(),
            TextColumn::make('slug')->searchable()->toggleable(isToggledHiddenByDefault: true),
            IconColumn::make('is_enabled')->label('Enabled')->boolean()->toggleable(),
            IconColumn::make('is_blocked')->label('Blocked')->boolean()->toggleable()])->filters([
                SelectFilter::make('group')->label('Product group')->relationship('group', 'name', hasEmptyOption: true)
                    ->emptyRelationshipOptionLabel('Ungrouped')->searchable()->preload(),
            ])->recordActions([EditAction::make()->using(fn (Model $record, array $data) => CatalogWrites::update($record, $data)),
                ...(AdminAccess::plugin()->hasOutages() ? [LicensingActions::startOutage(), LicensingActions::addTime()] : [])])->defaultSort('id', 'desc');
    }

    /** @return Builder<Product> */
    public static function getEloquentQuery(): Builder
    {
        return Product::query()->with(['group']);
    }

    public static function getPages(): array
    {
        return ['index' => ManageProducts::route('/')];
    }
}
