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
            TextColumn::make('status')->label('Status')->badge()->state(fn (Entitlement $record): string => self::statusLabel($record))
                ->color(fn (Entitlement $record): string => match (true) {
                    $record->isRevoked(), $record->isSuspended() => 'danger',
                    $record->paused_at !== null => 'warning',
                    $record->is_lifetime || (bool) $record->expires_at?->isFuture() => 'success',
                    default => 'gray',
                })->toggleable(),
            IconColumn::make('device_bound')->label('Device linked')->boolean()->toggleable(isToggledHiddenByDefault: true)
                ->state(fn (Entitlement $record): bool => $record->binding?->hwid !== null),
            TextColumn::make('bound_at')->label('Linked at')->dateTime()->placeholder('Not linked')->toggleable(isToggledHiddenByDefault: true)
                ->state(fn (Entitlement $record): ?CarbonImmutable => $record->binding?->bound_at)])->recordActions([LicensingActions::suspend(),
                    LicensingActions::unsuspend(),
                    LicensingActions::cancelLicense(),
                    LicensingActions::restoreLicense(),
                    LicensingActions::extendEntitlement(),
                    LicensingActions::pauseEntitlement(),
                    LicensingActions::resumeEntitlement(),
                    LicensingActions::reset(),
                    LicensingActions::inspectDevice(), ])->defaultSort('id', 'desc');
    }

    public static function statusLabel(Entitlement $record): string
    {
        return match (true) {
            $record->isRevoked() => 'Cancelled',
            $record->isSuspended() => $record->suspended_until === null ? 'Suspended' : 'Suspended until '.$record->suspended_until->setTimezone(config('app.timezone'))->format('M j, H:i'),
            $record->paused_at !== null => 'Paused ('.intdiv((int) $record->remaining_seconds, 86400).' days left)',
            $record->is_lifetime => 'Lifetime',
            (bool) $record->expires_at?->isFuture() => 'Active',
            $record->expires_at === null => 'No time yet',
            default => 'Expired',
        };
    }

    public static function getPages(): array
    {
        return ['index' => ManageEntitlements::route('/')];
    }
}
