<?php

namespace LicenseKit\Filament\Resources\Licenses\Tables;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use LicenseKit\Core\Actions\ManageLicenses;
use LicenseKit\Core\Models\License;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Enums\LicenseState;
use LicenseKit\Filament\Resources\LicenseKeys\LicenseKeyResource;
use LicenseKit\Filament\Resources\Licenses\LicenseResource;
use LicenseKit\Filament\Support\TimeLeft;

class LicensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.'.config('licensekit.user_label'))->label('Customer')->placeholder('Deleted user')->weight(FontWeight::Medium)
                    ->description(fn (License $record): string => $record->product->name)->searchable(),
                TextColumn::make('product.name')->label('Product')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label('Status')->badge()->state(fn (License $record): LicenseState => LicenseState::for($record)),
                TextColumn::make('expires_at')->label('Time left')->state(fn (License $record): string => TimeLeft::for($record))
                    ->tooltip(fn (License $record): ?string => $record->expires_at === null ? null : 'Ends '.TimeLeft::date($record->expires_at))
                    ->sortable(),
                IconColumn::make('device_locked')->label('PC')->state(fn (License $record): bool => $record->device !== null)
                    ->icon(fn (bool $state): Heroicon => $state ? Heroicon::OutlinedLockClosed : Heroicon::OutlinedLockOpen)
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->tooltip(fn (License $record): string => $record->device === null ? 'Not locked to a PC yet'
                        : 'Locked to a PC since '.TimeLeft::date($record->device_bound_at))->visibleFrom('md'),
                TextColumn::make('keys_count')->label('Keys redeemed')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('First seen')->since()->dateTimeTooltip(timezone: config('app.timezone'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([SelectFilter::make('product_id')->label('Product')->relationship('product', 'name')])
            ->toolbarActions([
                BulkAction::make('add_time')->label('Add time')->icon(Heroicon::OutlinedPlusCircle)->authorize(fn () => AdminAccess::isSupport())
                    ->schema([TextInput::make('days')->label('Days to add')->integer()->minValue(1)->maxValue(3650)->required()])
                    ->action(function (Collection $records, array $data): void {
                        $changed = 0;
                        foreach ($records as $record) {
                            if (LicenseResource::canView($record) && LicensingActions::mayChange($record, true)
                                && ! $record->is_lifetime && ! $record->isRevoked() && ! $record->isSuspended() && $record->user_id !== null) {
                                app(ManageLicenses::class)->extend($record, (int) $data['days'] * 86400, AdminAccess::actor());
                                $changed++;
                            }
                        }
                        Notification::make()->title('Added '.$data['days'].' days to '.$changed.' '.($changed === 1 ? 'license' : 'licenses'))->success()->send();
                    })->deselectRecordsAfterCompletion(),
                BulkAction::make('suspend')->label('Suspend')->icon(Heroicon::OutlinedNoSymbol)->color('danger')->authorize(fn () => AdminAccess::isSupport())
                    ->requiresConfirmation()->modalDescription('The selected customers can no longer use these products. Already suspended licenses are skipped.')
                    ->action(function (Collection $records): void {
                        $changed = 0;
                        foreach ($records as $record) {
                            if (LicenseResource::canView($record) && LicensingActions::mayChange($record, true) && ! $record->isRevoked() && ! $record->isSuspended()) {
                                app(ManageLicenses::class)->suspend($record, AdminAccess::actor());
                                $changed++;
                            }
                        }
                        Notification::make()->title('Suspended '.$changed.' '.($changed === 1 ? 'license' : 'licenses'))->success()->send();
                    })->deselectRecordsAfterCompletion(),
            ])
            ->recordActions([
                LicensingActions::extendLicense()->iconButton()->tooltip('Add time'),
                ActionGroup::make(LicensingActions::licenseMenu())->label('Manage')->tooltip('Manage'),
            ])
            ->recordUrl(fn (License $record): string => LicenseResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('No licenses yet')
            ->emptyStateDescription('A license appears when a customer redeems a key, or when you give one.')
            ->emptyStateIcon('heroicon-o-identification')
            ->emptyStateActions([Action::make('go_to_keys')->label('Generate keys')->icon(Heroicon::OutlinedKey)->url(fn (): string => LicenseKeyResource::getUrl())
                ->visible(fn (): bool => AdminAccess::isAdmin())])
            ->persistFiltersInSession()->persistSearchInSession()
            ->defaultSort('id', 'desc');
    }
}
