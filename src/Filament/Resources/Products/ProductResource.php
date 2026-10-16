<?php

namespace LicenseKit\Filament\Resources\Products;

use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\Actions\LicensingActions;
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
            TextInput::make('slug')->required()->maxLength(100)->regex('/^[a-z0-9-]+$/')->unique(ignoreRecord: true),
            Select::make('status')->options(['active' => 'Active', 'maintenance' => 'Maintenance', 'discontinued' => 'Discontinued'])->default('active')->required()->selectablePlaceholder(false)
                ->helperText('Maintenance: the software stops working for everyone and licenses keep their time. Discontinued: no new keys, existing licenses keep working.'),
            Toggle::make('requires_hwid')->label('Lock each license to one device')->default(true)->live()
                ->helperText('The first PC a customer logs in from is locked to their license. Turn off to allow any PC.'),
            Select::make('device_move_cooldown_days')->label('Customers can move their license to a new PC')
                ->options([0 => 'Any time', 1 => 'Once a day', 7 => 'Once a week', 14 => 'Every 2 weeks', 30 => 'Once a month'])
                ->placeholder('Never (support only)')->default(7)->visible(fn (Get $get): bool => (bool) $get('requires_hwid')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable()->toggleable(),
            TextColumn::make('slug')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('status')->badge()->formatStateUsing(fn (string $state): string => ucfirst($state))
                ->color(fn (string $state): string => match ($state) {
                    'active' => 'success', 'maintenance' => 'warning', default => 'gray'
                })->toggleable()])->recordActions([EditAction::make()->using(fn (Model $record, array $data) => CatalogWrites::update($record, $data)),
                    LicensingActions::addTime()])->defaultSort('id', 'desc');
    }

    /** @return Builder<Product> */
    public static function getEloquentQuery(): Builder
    {
        return Product::query();
    }

    public static function getPages(): array
    {
        return ['index' => ManageProducts::route('/')];
    }
}
