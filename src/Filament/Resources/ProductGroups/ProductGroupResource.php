<?php

namespace LicenseKit\Filament\Resources\ProductGroups;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Models\ProductGroup;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\CatalogWrites;
use LicenseKit\Filament\Resources\LicensingResource;
use LicenseKit\Filament\Resources\ProductGroups\Pages\ManageProductGroups;

class ProductGroupResource extends LicensingResource
{
    protected static ?string $slug = 'licensekit/product-groups';

    protected static ?string $model = ProductGroup::class;

    protected static bool $editable = true;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->required()->maxLength(255)]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable(),
            TextColumn::make('products_count')->counts('products')->label('Products')])
            ->recordActions([
                EditAction::make()->using(fn (Model $record, array $data) => CatalogWrites::update($record, $data)),
                DeleteAction::make()->requiresConfirmation()->modalDescription('Delete this group? Its products will remain and become ungrouped. Licenses and access will not change.')
                    ->using(fn (ProductGroup $record): bool => CatalogWrites::deleteGroup($record)),
            ])->defaultSort('id', 'desc');
    }

    public static function canDelete(Model $record): bool
    {
        return AdminAccess::can('delete', $record, static::class);
    }

    public static function getPages(): array
    {
        return ['index' => ManageProductGroups::route('/')];
    }
}
