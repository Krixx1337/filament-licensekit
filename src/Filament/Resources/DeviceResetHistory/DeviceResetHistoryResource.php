<?php

namespace LicenseKit\Filament\Resources\DeviceResetHistory;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Models\DeviceResetHistory;
use LicenseKit\Core\Users\ConfiguredUserModel;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Resources\DeviceResetHistory\Pages\ManageDeviceResetHistory;
use LicenseKit\Filament\Resources\LicensingResource;

class DeviceResetHistoryResource extends LicensingResource
{
    protected static ?string $slug = 'licensekit/device-reset-history';

    protected static ?string $model = DeviceResetHistory::class;

    protected static ?string $navigationLabel = 'HWID reset history';

    protected static ?string $modelLabel = 'HWID reset';

    protected static ?string $pluralModelLabel = 'HWID reset history';

    public static function table(Table $table): Table
    {
        $display = config('licensekit.user.display_column');

        return $table->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'actor', 'product']))
            ->columns([
                TextColumn::make('user_id')->label('User ID')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user.'.$display)->label('User')->searchable()->placeholder(fn (DeviceResetHistory $record): string => $record->user_id ?: 'Unavailable')->toggleable(),
                TextColumn::make('scope')->label('Scope')->formatStateUsing(fn (string $state): string => $state === 'global' ? 'All products' : 'Per product')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('product_id')->label('Product ID')->placeholder('All products')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('product.name')->label('Product')->placeholder(fn (DeviceResetHistory $record): string => $record->scope === 'global' ? 'All products' : ($record->product_id ?: 'Unavailable'))->toggleable(),
                TextColumn::make('device_cleared')->label('Device cleared')->state(fn (DeviceResetHistory $record): string => static::deviceClearedLabel($record->before_hwid))->wrap()->toggleable(),
                TextColumn::make('reset_at')->dateTime()->timezone('UTC')->label('Reset at (UTC)')->toggleable(),
                TextColumn::make('performed_by')->label('Performed by')->badge()->toggleable(),
                TextColumn::make('actor_id')->label('Actor ID')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('actor.'.$display)->label('Actor')->searchable()->placeholder(fn (DeviceResetHistory $record): string => $record->actor_id ?: 'Unavailable')->toggleable(),
                TextColumn::make('reason')->limit(60)->wrap()->toggleable(),
            ])->filters([
                SelectFilter::make('user_id')->label('User')->searchable()->preload()
                    ->options(fn (): array => static::userOptions(''))
                    ->getSearchResultsUsing(fn (string $search): array => static::userOptions($search))
                    ->getOptionLabelUsing(fn (mixed $value): string => static::userLabel($value)),
                SelectFilter::make('scope')->options(['global' => 'All products', 'per_product' => 'Per product']),
                SelectFilter::make('product')->relationship('product', 'name')->searchable(),
                SelectFilter::make('performed_by')->options(['customer' => 'Customer', 'admin' => 'Admin']),
            ])->recordActions([
                Action::make('details')->label('View details')->authorize(fn (DeviceResetHistory $record): bool => AdminAccess::can('view', $record, static::class))
                    ->schema([Textarea::make('reason')->default(function (DeviceResetHistory $record): ?string {
                        abort_unless(static::canView($record), 403);

                        return $record->reason;
                    })->disabled()->dehydrated(false)->rows(8),
                        Textarea::make('before_hwid')->label('Device cleared')->default(function (DeviceResetHistory $record): string {
                            AdminAccess::authorize('view', $record, static::class);
                            $payload = $record->fresh()->before_hwid;

                            return $payload === null ? 'Unavailable' : json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
                        })->disabled()->dehydrated(false)->rows(12)])
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close'),
            ])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageDeviceResetHistory::route('/')];
    }

    /** @param  array<string, mixed>|null  $payload */
    private static function deviceClearedLabel(?array $payload): string
    {
        if ($payload === null) {
            return 'Unavailable';
        }
        if (count($payload) === 1 && is_string($payload['value'] ?? null)) {
            return $payload['value'];
        }

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /** @return array<int|string, string> */
    private static function userOptions(string $search): array
    {
        $display = config('licensekit.user.display_column');
        $model = ConfiguredUserModel::className();
        $keyName = (new $model)->getKeyName();
        $query = $model::query();
        if ($search !== '') {
            $query->where(fn (Builder $query): Builder => $query->where($display, 'like', '%'.$search.'%')->orWhere($keyName, 'like', '%'.$search.'%'));
        }
        $users = $query->limit(50)->get()->mapWithKeys(function (Model $user) use ($display, $keyName): array {
            $label = $user->getAttribute($display);

            return [(string) $user->getAttribute($keyName) => ($label === null || $label === '') ? (string) $user->getAttribute($keyName) : (string) $label];
        })->all();
        if ($search === '') {
            return $users;
        }
        $orphans = DeviceResetHistory::query()->where('user_id', 'like', '%'.$search.'%')->distinct()->limit(50)->pluck('user_id', 'user_id');
        foreach ($orphans as $id) {
            $users[$id] ??= (string) $id;
        }

        return $users;
    }

    private static function userLabel(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $user = ConfiguredUserModel::className()::query()->whereKey($value)->first();

        return (string) ($user?->getAttribute(config('licensekit.user.display_column')) ?? $value);
    }
}
