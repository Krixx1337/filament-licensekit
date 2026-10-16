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
use LicenseKit\Filament\Resources\Licenses\Pages\ManageLicenses;
use LicenseKit\Filament\Resources\Licenses\Pages\ViewLicense;
use LicenseKit\Filament\Resources\LicensingResource;

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
            TextColumn::make('status')->label('Status')->badge()->state(fn (License $record): string => self::statusLabel($record))
                ->color(fn (License $record): string => match (true) {
                    $record->isRevoked(), $record->isSuspended() => 'danger',
                    $record->paused_at !== null => 'warning',
                    $record->is_lifetime || (bool) $record->expires_at?->isFuture() => 'success',
                    default => 'gray',
                })->toggleable(),
            IconColumn::make('device_bound')->label('Device locked')->boolean()->toggleable()
                ->state(fn (License $record): bool => $record->device !== null),
            TextColumn::make('keys_count')->label('Keys redeemed'),
            TextColumn::make('device_bound_at')->label('Linked at')->dateTime()->placeholder('Not linked')->toggleable(isToggledHiddenByDefault: true)])->filters([
                SelectFilter::make('product_id')->label('Product')->relationship('product', 'name'),
                SelectFilter::make('status')->options(['active' => 'Active', 'expired' => 'Expired', 'paused' => 'Paused', 'suspended' => 'Suspended', 'revoked' => 'Cancelled'])
                    ->query(function ($query, array $data) {
                        return match ($data['value'] ?? '') {
                            'active' => $query->where('status', 'active')->where(fn ($q) => $q->where('is_lifetime', true)->orWhere('expires_at', '>', now())),
                            'expired' => $query->where('status', 'active')->where('is_lifetime', false)->where('expires_at', '<=', now()),
                            'paused', 'suspended', 'revoked' => $query->where('status', $data['value']),
                            default => $query,
                        };
                    }),
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
                            if (static::canView($record) && LicensingActions::mayChange($record, true) && ! $record->isRevoked()) {
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

    public static function statusLabel(License $record): string
    {
        return match (true) {
            $record->isRevoked() => 'Cancelled',
            $record->isSuspended() => $record->suspended_until === null ? 'Suspended' : 'Suspended until '.$record->suspended_until->setTimezone(config('app.timezone'))->format('M j, H:i'),
            $record->paused_at !== null => 'Paused ('.intdiv((int) $record->remaining_seconds, 86400).' days left)',
            $record->is_lifetime => 'Lifetime',
            $record->product->freeAccessActive() && ! (bool) $record->expires_at?->isFuture() => 'Free access',
            $record->expires_at?->isFuture() && $record->expires_at->lessThanOrEqualTo(now()->addDays(7)) => 'Expiring soon',
            (bool) $record->expires_at?->isFuture() => 'Active',
            $record->expires_at === null => 'No time yet',
            default => 'Expired',
        };
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['user.'.config('licensekit.user_label'), 'keys.key'];
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
