<?php

namespace LicenseKit\Filament\Resources\DeviceBans;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LicenseKit\Core\Actions\DeviceBans;
use LicenseKit\Core\Models\DeviceBan;
use LicenseKit\Core\Users\ConfiguredUserModel;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Navigation;
use LicenseKit\Filament\Resources\LicensingResource;

class DeviceBanResource extends LicensingResource
{
    protected static ?string $model = DeviceBan::class;

    protected static bool $global = true;

    protected static ?string $slug = 'licensekit/device-bans';

    protected static string|\UnitEnum|null $navigationGroup = Navigation::PROTECTION;

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Banned PCs';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-no-symbol';

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('device')->label('PC fingerprint')->fontFamily(FontFamily::Mono)->limit(24)->searchable(['device', 'note'])
            ->description(fn (DeviceBan $record): ?string => $record->note),
            TextColumn::make('product.name')->label('Banned from')->placeholder('All products')->badge()->color('danger'),
            TextColumn::make('created_by')->label('Banned by')->formatStateUsing(fn (string $state): string => self::banner($state)),
            TextColumn::make('created_at')->label('When')->since()->dateTimeTooltip(timezone: config('app.timezone'))])
            ->recordActions([Action::make('unban')->label('Unban')->icon(Heroicon::OutlinedLockOpen)->requiresConfirmation()->modalIcon(Heroicon::OutlinedLockOpen)
                ->authorize(fn () => AdminAccess::isAdmin())
                ->modalDescription('The PC can connect again. Suspended licenses remain suspended until you unsuspend them.')
                ->action(function (DeviceBan $record): void {
                    app(DeviceBans::class)->unban($record, AdminAccess::actor());
                    Notification::make()->title('PC unbanned')->success()->send();
                })])
            ->emptyStateHeading('No banned PCs')->emptyStateIcon('heroicon-o-no-symbol')
            ->emptyStateDescription('Ban a PC from a license\'s Manage menu. A banned PC cannot log in with any account.')
            ->defaultSort('id', 'desc');
    }

    /** "user:5" becomes that person's name. */
    private static function banner(string $actor): string
    {
        return str_starts_with($actor, 'user:')
            ? (ConfiguredUserModel::query()->whereKey(substr($actor, 5))->value(config('licensekit.user_label')) ?? $actor) : $actor;
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageDeviceBans::route('/')];
    }
}
