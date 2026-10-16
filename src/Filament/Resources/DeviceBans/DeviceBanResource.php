<?php

namespace LicenseKit\Filament\Resources\DeviceBans;

use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LicenseKit\Core\Actions\DeviceBans;
use LicenseKit\Core\Models\DeviceBan;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Resources\LicensingResource;

class DeviceBanResource extends LicensingResource
{
    protected static ?string $model = DeviceBan::class;

    protected static ?string $slug = 'licensekit/device-bans';

    protected static ?string $navigationParentItem = 'Settings';

    protected static ?string $navigationLabel = 'Device bans';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-no-symbol';

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('device')->limit(24)->searchable(), TextColumn::make('product.name')->label('Product')->placeholder('All products'),
            TextColumn::make('note')->searchable()->limit(60), TextColumn::make('created_by')->label('Banned by'),
            TextColumn::make('created_at')->since()->dateTimeTooltip(config('app.timezone'))])
            ->recordActions([Action::make('unban')->label('Unban')->requiresConfirmation()->authorize(fn () => AdminAccess::isAdmin())
                ->modalDescription('The PC can connect again. Suspended licenses remain suspended until you unsuspend them.')
                ->action(fn (DeviceBan $record) => app(DeviceBans::class)->unban($record, AdminAccess::actor()))]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageDeviceBans::route('/')];
    }
}
