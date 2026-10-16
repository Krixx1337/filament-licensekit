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
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Resources\ApiConnections\Pages\ManageApiConnections as ManageConnectionsPage;
use LicenseKit\Filament\Resources\LicensingResource;

class ApiConnectionResource extends LicensingResource
{
    protected static ?string $slug = 'licensekit/connections';

    protected static ?string $navigationLabel = 'Store & reseller connections';

    protected static ?string $modelLabel = 'connection';

    protected static ?string $model = ApiConnection::class;

    protected static bool $editable = true;

    protected static ?string $navigationParentItem = 'Settings';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
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
            TextColumn::make('max_per_request')->label('Max keys'), TextColumn::make('last_used_at')->since()->placeholder('Never')])
            ->recordActions([
                EditAction::make()->using(fn (ApiConnection $record, array $data) => app(ManageApiConnections::class)->save($record, $data, AdminAccess::actor())),
                Action::make('regenerate_token')->label('Regenerate token')->authorize(fn () => AdminAccess::isAdmin())->requiresConfirmation()
                    ->modalDescription('The old token stops working immediately. Existing keys and licenses keep working.')
                    ->action(function (ApiConnection $record, $livewire): void {
                        $token = app(ManageApiConnections::class)->regenerate($record, AdminAccess::actor());
                        $livewire->replaceMountedAction('result', ['title' => 'Connection token', 'text' => $token, 'token' => true]);
                    }),
            ])->emptyStateHeading('No connections yet')->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageConnectionsPage::route('/')];
    }
}
