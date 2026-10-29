<?php

namespace LicenseKit\Filament\Resources\ApiConnections;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LicenseKit\Core\Actions\ManageApiConnections;
use LicenseKit\Core\Models\ApiConnection;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Navigation;
use LicenseKit\Filament\Resources\ApiConnections\Pages\ManageApiConnections as ManageConnectionsPage;
use LicenseKit\Filament\Resources\LicensingResource;

class ApiConnectionResource extends LicensingResource
{
    protected static ?string $slug = 'licensekit/connections';

    protected static ?string $navigationLabel = 'Store connections';

    protected static ?string $modelLabel = 'store connection';

    protected static ?string $model = ApiConnection::class;

    protected static bool $global = true;

    protected static bool $editable = true;

    protected static string|\UnitEnum|null $navigationGroup = Navigation::SETUP;

    protected static ?int $navigationSort = 20;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            LicensingActions::userSelect()->label('Owner (optional)')->required(false)
                ->helperText('The reseller who runs this bot. Suspending their account stops this token.'),
            Toggle::make('is_active')->label('Active')->default(true),
            Toggle::make('all_products')->label('All current and future products')->default(true)->live()->dehydrated(false)
                ->afterStateHydrated(fn ($component, ?ApiConnection $record) => $record === null ? null : $component->state($record->allowed_product_ids === null)),
            Select::make('allowed_product_ids')->label('Products')->options(fn () => Product::pluck('name', 'id'))->multiple()->default([])
                ->visible(fn (Get $get): bool => ! $get('all_products'))->dehydratedWhenHidden()
                ->dehydrateStateUsing(fn ($state, Get $get) => $get('all_products') ? null : array_map('intval', $state ?? [])),
            TextInput::make('max_per_request')->label('Max keys per request')->integer()->minValue(1)->maxValue(config('licensekit.max_batch_size'))->default(100)->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable(), IconColumn::make('is_active')->label('Active')->boolean(),
            TextColumn::make('user.'.config('licensekit.user_label'))->label('Owner')->placeholder('Your shop'),
            TextColumn::make('max_per_request')->label('Max keys'), TextColumn::make('last_used_at')->since()->placeholder('Never')])
            ->recordActions([
                EditAction::make()->using(fn (ApiConnection $record, array $data) => app(ManageApiConnections::class)->save($record, $data, AdminAccess::actor())),
                Action::make('regenerate_token')->label('Regenerate token')->authorize(fn () => AdminAccess::isAdmin())->requiresConfirmation()
                    ->modalDescription('The old token stops working immediately. Existing keys and licenses keep working.')
                    ->action(function (ApiConnection $record, $livewire): void {
                        $token = app(ManageApiConnections::class)->regenerate($record, AdminAccess::actor());
                        $livewire->replaceMountedAction('result', ['title' => 'Connection token', 'text' => $token, 'token' => true]);
                    }),
            ])->emptyStateHeading('No store connections yet')->emptyStateIcon('heroicon-o-building-storefront')
            ->emptyStateDescription(fn (): string => 'Let your shop create keys automatically after each sale. Create a connection, copy its token, and give your '
                .'developer the store endpoint: '.url(is_string($prefix = config('licensekit.store_api_prefix')) ? $prefix : '').'. See docs/selling-keys.md.')
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageConnectionsPage::route('/')];
    }
}
