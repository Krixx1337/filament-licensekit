<?php

namespace LicenseKit\Filament\Resources\Products;

use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\Resources\LicensingResource;
use LicenseKit\Filament\Resources\Products\Pages\CreateProduct;
use LicenseKit\Filament\Resources\Products\Pages\EditProduct;
use LicenseKit\Filament\Resources\Products\Pages\ListProducts;
use LicenseKit\Filament\Resources\Products\Schemas\ProductForm;
use LicenseKit\Filament\Resources\Products\Tables\ProductsTable;

class ProductResource extends LicensingResource
{
    protected static ?int $navigationSort = 40;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?string $slug = 'licensekit/products';

    protected static ?string $model = Product::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $editable = true;

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    /** @return Builder<Product> */
    public static function getEloquentQuery(): Builder
    {
        return Product::query()->with('plans')->withCount(['licenses as active_licenses_count' => fn (Builder $query) => (new License)->scopeEffectiveStatus($query, License::ACTIVE)]);
    }

    public static function getPages(): array
    {
        return ['index' => ListProducts::route('/'), 'create' => CreateProduct::route('/create'), 'edit' => EditProduct::route('/{record}/edit')];
    }
}
