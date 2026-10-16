<?php

namespace LicenseKit\Filament\Resources\Licenses;

use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Models\License;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Enums\LicenseState;
use LicenseKit\Filament\Resources\Licenses\Pages\ManageLicenses;
use LicenseKit\Filament\Resources\Licenses\Pages\ViewLicense;
use LicenseKit\Filament\Resources\LicensingResource;
use LicenseKit\Filament\Support\TimeLeft;

class LicenseResource extends LicensingResource
{
    protected static ?int $navigationSort = 20;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static ?string $slug = 'licensekit/licenses';

    protected static ?string $model = License::class;

    protected static string $access = AdminAccess::SUPPORT;

    protected static ?string $navigationLabel = 'Licenses';

    protected static ?string $modelLabel = 'license';

    protected static ?string $pluralModelLabel = 'Licenses';

    protected static bool $editable = false;

    /** @return Builder<Model> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'product'])->withCount('keys');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('user.'.config('licensekit.user_label'))->label('Customer')->placeholder('Deleted user')->searchable()->toggleable(),
            TextColumn::make('product.name')->label('Product')->searchable()->toggleable(),
            IconColumn::make('is_lifetime')->label('Lifetime')->boolean()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('expires_at')->label('Expires')->since()->dateTimeTooltip()->placeholder(fn (License $record): string => $record->is_lifetime ? 'Lifetime' : ($record
                ->paused_at !== null ? 'Paused' : 'No expiry'))->toggleable(),
            TextColumn::make('paused_at')->label('Paused at')->dateTime()->placeholder('Not paused')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('remaining_seconds')->label('Banked time')
                ->formatStateUsing(fn (int $state): string => floor($state / 86400).'d '.floor(($state % 86400) / 3600).'h '.floor(($state % 3600) / 60).'m '.($state % 60).'s')
                ->placeholder('None')->toggleable(),
            TextColumn::make('status')->label('Status')->badge()->state(fn (License $record): LicenseState => LicenseState::for($record))
                ->description(fn (License $record): string => TimeLeft::for($record))->toggleable(),
            IconColumn::make('device_bound')->label('Device locked')->boolean()->toggleable()
                ->state(fn (License $record): bool => $record->device !== null),
            TextColumn::make('keys_count')->label('Keys redeemed'),
            TextColumn::make('device_bound_at')->label('Linked at')->dateTime()->placeholder('Not linked')->toggleable(isToggledHiddenByDefault: true)])->filters([
                SelectFilter::make('product_id')->label('Product')->relationship('product', 'name'),
                SelectFilter::make('status')->options(['active' => 'Active', 'expired' => 'Expired', 'paused' => 'Paused', 'suspended' => 'Suspended', 'revoked' => 'Cancelled'])
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null) ? $query->effectiveStatus($data['value']) : $query),
            ])->toolbarActions([
                BulkAction::make('add_time')->label('Add time')->authorize(fn () => AdminAccess::isSupport())
                    ->schema([TextInput::make('days')->integer()->minValue(1)->maxValue(3650)->required()])
                    ->action(function ($records, array $data): void {
                        foreach ($records as $record) {
                            if (static::canView($record) && LicensingActions::mayChange($record, true)
                                && ! $record->is_lifetime && ! $record->isRevoked() && ! $record->isSuspended() && $record->user_id !== null) {
                                app(\LicenseKit\Core\Actions\ManageLicenses::class)->extend($record, (int) $data['days'] * 86400, AdminAccess::actor());
                            }
                        }
                    }),
                BulkAction::make('suspend')->label('Suspend')->authorize(fn () => AdminAccess::isSupport())->requiresConfirmation()
                    ->action(function ($records): void {
                        foreach ($records as $record) {
                            if (static::canView($record) && LicensingActions::mayChange($record, true) && ! $record->isRevoked() && ! $record->isSuspended()) {
                                app(\LicenseKit\Core\Actions\ManageLicenses::class)->suspend($record, AdminAccess::actor());
                            }
                        }
                    }),
            ])->recordActions([ActionGroup::make([LicensingActions::suspend(),
                LicensingActions::unsuspend(),
                LicensingActions::cancelLicense(),
                LicensingActions::restoreLicense(),
                LicensingActions::extendLicense(),
                LicensingActions::pauseLicense(),
                LicensingActions::resumeLicense(),
                LicensingActions::reset(),
                LicensingActions::inspectDevice(), LicensingActions::banDevice(), LicensingActions::licenseNote(), ])])->recordUrl(fn (License $record) => static::getUrl('view',
                    ['record' => $record]))->defaultSort('id', 'desc');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['user.'.config('licensekit.user_label'), 'keys.key'];
    }

    /** @return Builder<Model> */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['user', 'product']);
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return $record instanceof License ? ['Status' => LicenseState::for($record)->getLabel(), 'Time left' => TimeLeft::for($record)] : [];
    }

    public static function getNavigationBadge(): ?string
    {
        $count = License::query()->effectiveStatus('expiring')->count();

        return $count === 0 ? null : (string) $count;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return 'Expiring in the next '.LicenseState::EXPIRING_DAYS.' days';
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        return $record->getRelationValue('user')?->getAttribute(config('licensekit.user_label')).' / '.$record->getRelationValue('product')?->getAttribute('name');
    }

    public static function getPages(): array
    {
        return ['index' => ManageLicenses::route('/'), 'view' => ViewLicense::route('/{record}')];
    }
}
