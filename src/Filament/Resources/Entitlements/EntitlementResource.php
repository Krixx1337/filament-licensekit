<?php

namespace LicenseKit\Filament\Resources\Entitlements;

use Carbon\CarbonImmutable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\Resources\Entitlements\Pages\ManageEntitlements;
use LicenseKit\Filament\Resources\LicensingResource;

class EntitlementResource extends LicensingResource
{
    protected static ?string $slug = 'licensekit/entitlements';

    protected static ?string $model = Entitlement::class;

    protected static ?string $navigationLabel = 'Licenses';

    protected static ?string $modelLabel = 'license';

    protected static ?string $pluralModelLabel = 'Licenses';

    protected static bool $editable = false;

    /** @return Builder<Model> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'product']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('user.'.config('licensekit.user.display_column'))->label('User')->placeholder('Deleted user')->searchable()->toggleable(),
            TextColumn::make('product.name')->label('Product')->searchable()->toggleable(),
            IconColumn::make('is_lifetime')->label('Lifetime')->boolean()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('expires_at')->label('Ends')->dateTime()->placeholder(fn (Entitlement $record): string => $record->is_lifetime ? 'Lifetime' : ($record->paused_at !== null ? 'Paused' : 'No expiry'))->toggleable(),
            TextColumn::make('paused_at')->label('Paused at')->dateTime()->placeholder('Not paused')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('remaining_seconds')->label('Banked time')->formatStateUsing(fn (int $state): string => floor($state / 86400).'d '.floor(($state % 86400) / 3600).'h '.floor(($state % 3600) / 60).'m '.($state % 60).'s')->placeholder('None')->toggleable(),
            IconColumn::make('is_revoked')->label('Cancelled')->boolean()->toggleable(),
            IconColumn::make('is_blocked')->label('Blocked')->boolean()->toggleable(),
            IconColumn::make('device_bound')->label('Device linked')->boolean()->toggleable(isToggledHiddenByDefault: true)
                ->state(fn (Entitlement $record): bool => $record->binding?->hwid !== null),
            TextColumn::make('bound_at')->label('Linked at')->dateTime()->placeholder('Not linked')->toggleable(isToggledHiddenByDefault: true)
                ->state(fn (Entitlement $record): ?CarbonImmutable => $record->binding?->bound_at)])->recordActions([LicensingActions::status('is_revoked'),
                    LicensingActions::status('is_blocked'),
                    LicensingActions::extendEntitlement(),
                    LicensingActions::pauseEntitlement(),
                    LicensingActions::resumeEntitlement(),
                    LicensingActions::reset(),
                    LicensingActions::inspectDevice(), ])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageEntitlements::route('/')];
    }
}
