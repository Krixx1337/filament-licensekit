<?php

namespace LicenseKit\Filament\Actions;

use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Actions\LicensedDevices;
use LicenseKit\Core\Actions\ManageEntitlements;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Core\Users\ConfiguredUserModel;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Modules\Outages\Actions\ManageOutages;
use LicenseKit\Modules\Outages\Models\Outage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LicensingActions
{
    public static function actor(): Model
    {
        return AdminAccess::actor();
    }

    public static function identity(): string
    {
        return AdminAccess::identity();
    }

    public static function editNote(): Action
    {
        return Action::make('edit_note')->label('Edit note')->authorize(fn (LicenseKey $record): bool => AdminAccess::can('updateNote', $record))
            ->fillForm(fn (LicenseKey $record): array => ['note' => $record->getAttribute('note')])->schema([TextInput::make('note')->maxLength(255)])
            ->action(fn (LicenseKey $record, array $data) => app(ManageEntitlements::class)->updateNote($record, self::actor(), $data['note'] ?? null));
    }

    public static function exportBatch(): Action
    {
        return Action::make('export_unclaimed')->label('Export unclaimed keys')->authorize(fn (Batch $record): bool => AdminAccess::can('exportUnclaimed', $record))
            ->action(function (Batch $record): ?StreamedResponse {
                $keys = app(ManageEntitlements::class)->exportUnclaimedBatch($record, self::actor());
                if ($keys === []) {
                    Notification::make()->title('No unclaimed keys to export')->warning()->send();

                    return null;
                }
                $text = implode("\r\n", $keys);

                return response()->streamDownload(function () use ($text): void {
                    echo $text;
                }, 'license-keys-'.$record->id.'.txt', ['Cache-Control' => 'private, no-store']);
            });
    }

    public static function revokeBatch(): Action
    {
        return Action::make('revoke_unclaimed')->label('Revoke unclaimed keys')->color('danger')->requiresConfirmation()
            ->modalDescription('Revokes remaining unclaimed keys. Claimed licenses remain active.')
            ->authorize(fn (Batch $record): bool => AdminAccess::can('revokeUnclaimed', $record))
            ->schema([Textarea::make('reason')->required()->maxLength(2000)])
            ->action(function (Batch $record, array $data): void {
                $count = app(ManageEntitlements::class)->revokeUnclaimedBatch($record, self::actor(), $data['reason']);
                Notification::make()->title('Revoked '.$count.' unclaimed keys in this batch')->success()->send();
            });
    }

    public static function createKeys(): Action
    {
        return Action::make('create_keys')->authorize(fn (LicensePlan $record): bool => AdminAccess::can('createKeys', $record))->label('Create keys')->schema([
            TextInput::make('quantity')->integer()->minValue(1)->maxValue(config('licensekit.maximum_batch_size'))->default(1)->required(),
            TextInput::make('reference')->default(fn (): string => (string) Str::uuid())->maxLength(128)->required(),
            TextInput::make('label')->label('Batch label')->maxLength(255),
            Toggle::make('download')->label('Download keys')->default(true),
        ])->action(function (LicensePlan $record, array $data): ?StreamedResponse {
            $batch = app(CreateBatch::class)->createFor(self::actor(), $record, (int) $data['quantity'], $data['reference'], label: $data['label'] ?? null);
            if (! ($data['download'] ?? true)) {
                Notification::make()->title('Batch #'.$batch->id.' created')->success()->send();

                return null;
            }
            $text = $batch->keys->pluck('key')->implode("\r\n");

            return response()->streamDownload(function () use ($text): void {
                echo $text;
            }, 'license-keys-'.$batch->id.'.txt', ['Cache-Control' => 'private, no-store']);
        });
    }

    public static function userSelect(): Select
    {
        return Select::make('user_id')->options(fn (): array => ConfiguredUserModel::className()::query()->limit(50)->pluck(config('licensekit.user.display_column'), (new (ConfiguredUserModel::className()))->getKeyName())->all())->searchable()->getSearchResultsUsing(fn (string $search): array => ConfiguredUserModel::className()::query()->where(config('licensekit.user.display_column'), 'like', '%'.$search.'%')->limit(50)->pluck(config('licensekit.user.display_column'), (new (ConfiguredUserModel::className()))->getKeyName())->all())->getOptionLabelUsing(fn ($value) => ConfiguredUserModel::className()::query()->whereKey($value)->first()?->getAttribute(config('licensekit.user.display_column')))->required();
    }

    public static function claim(): Action
    {
        return Action::make('claim')->authorize(fn (LicenseKey $record): bool => AdminAccess::can('claim', $record))->label('Give key to user')->visible(fn (LicenseKey $record): bool => $record->claimed_at === null && $record->revoked_at === null)
            ->schema([self::userSelect()])
            ->action(function (LicenseKey $record, array $data): void {
                app(ClaimLicense::class)->claimFor(self::actor(), ConfiguredUserModel::className()::findOrFail($data['user_id']), $record);
            });
    }

    public static function revokeKey(): Action
    {
        return Action::make('revoke_key')->authorize(fn (LicenseKey $record): bool => AdminAccess::can('revoke', $record))->color('danger')->visible(fn (LicenseKey $record): bool => $record->claimed_at === null && $record->revoked_at === null)
            ->schema([Textarea::make('reason')->required()->maxLength(2000)])
            ->action(fn (LicenseKey $record, array $data) => app(ManageEntitlements::class)->revokeKey($record, self::actor(), $data['reason']));
    }

    public static function status(string $field): Action
    {
        return Action::make($field)->authorize(fn (Entitlement $record): bool => AdminAccess::can($field === 'is_revoked' ? ($record->{$field} ? 'restoreAccess' : 'revoke') : ($record->{$field} ? 'unblock' : 'block'), $record))->label(fn (Entitlement $record): string => match (true) {
            $field === 'is_revoked' && $record->is_revoked => 'Restore license',
            $field === 'is_revoked' => 'Cancel license',
            $record->is_blocked => 'Restore access',
            default => 'Block access',
        })
            ->schema([Textarea::make('reason')->required()->maxLength(2000)])
            ->action(fn (Entitlement $record, array $data) => app(ManageEntitlements::class)->status($record, $field, ! $record->{$field}, self::actor(), $data['reason']));
    }

    public static function reset(): Action
    {
        return Action::make('reset_hwid')->authorize(fn (Entitlement $record): bool => $record->user !== null && AdminAccess::can('resetHwid', $record->product, context: [$record->user]))->label('Reset HWID')->modalDescription(fn (): string => config('licensekit.device_binding.scope') === 'global' ? 'Resets the account-wide device and invalidates device access for every product.' : 'Resets this product device and invalidates its device access.')->schema([Textarea::make('reason')->required()->maxLength(2000)])
            ->action(fn (Entitlement $record, array $data) => app(LicensedDevices::class)->adminReset($record, self::actor(), $data['reason']));
    }

    public static function inspectDevice(): Action
    {
        return Action::make('inspect_device')->authorize(fn (Entitlement $record): bool => $record->user !== null && AdminAccess::can('inspectDevice', $record->product, context: [$record->user]))->label('Inspect device')->schema([
            Textarea::make('descriptor')->default(function (Entitlement $record): string {
                AdminAccess::authorize('inspectDevice', $record->product, context: [$record->user]);

                return json_encode(['hwid' => $record->binding?->hwid, 'scope' => config('licensekit.device_binding.scope')], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            })
                ->rows(12)->disabled()->dehydrated(false),
        ])->before(fn (Entitlement $record) => AdminAccess::authorize('inspectDevice', $record->product, context: [$record->user]))->modalSubmitAction(false)->modalCancelActionLabel('Close');
    }

    /** @return array<int|string, string> */
    public static function productOptions(string $ability): array
    {
        return Product::orderBy('name')->get()->filter(fn (Product $product): bool => AdminAccess::can($ability, $product))->pluck('name', 'id')->all();
    }

    public static function startOutage(): Action
    {
        return Action::make('start_outage')->authorize(fn (?Product $record): bool => $record !== null ? AdminAccess::can('startOutage', $record) : self::productOptions('startOutage') !== [])->label('Mark service down')->schema([
            Select::make('product_id')->options(fn (): array => self::productOptions('startOutage'))->required()->default(fn (?Model $record) => $record instanceof Product ? $record->getKey() : null),
            TextInput::make('reference')->default(fn (): string => (string) Str::uuid())->required()->maxLength(128),
            Textarea::make('reason')->required()->maxLength(2000),
            Toggle::make('block')->label('Block access')->default(false),
        ])->action(fn (array $data) => app(ManageOutages::class)->start(Product::whereKey($data['product_id'])->firstOrFail(), $data['reference'], $data['reason'], self::actor(), $data['block']));
    }

    public static function stopOutage(): Action
    {
        return Action::make('stop_outage')->authorize(fn (Outage $record): bool => AdminAccess::can('stop', $record))->label('Mark service back')->visible(fn (Outage $record): bool => $record->ended_at === null && $record->stopped_at === null)
            ->schema([Select::make('compensate')->options(['yes' => 'Restore paid time', 'no' => 'End without adding time'])->required()])
            ->action(fn (Outage $record, array $data) => app(ManageOutages::class)->stop($record, $data['compensate'] === 'yes', self::actor()));
    }

    public static function addTime(): Action
    {
        return Action::make('add_time')->authorize(fn (?Product $record): bool => $record !== null ? AdminAccess::can('addTime', $record) : self::productOptions('addTime') !== [])->label('Add time')->modalDescription('Adds duration to existing expiry. For expired licenses, some or all of the added time may already have elapsed.')->schema([
            Select::make('product_id')->options(fn (): array => self::productOptions('addTime'))->required()->live()->default(fn (?Model $record) => $record instanceof Product ? $record->getKey() : null),
            TextInput::make('duration')->integer()->minValue(1)->maxValue(5256000)->default(1)->required(),
            Select::make('unit')->options(['86400' => 'Days', '3600' => 'Hours', '60' => 'Minutes'])->default('86400')->required(),
            Select::make('targets')->label('Target licenses')->options(['active' => 'Currently active licenses', 'incident' => 'Licenses from an earlier date'])->default('active')->required()->in(['active', 'incident'])->live(),
            DateTimePicker::make('eligible_at')->label('Incident started at (UTC)')->timezone('UTC')->maxDate(fn () => now('UTC'))->required(fn (Get $get): bool => $get('targets') === 'incident')->visible(fn (Get $get): bool => $get('targets') === 'incident')->live()
                ->helperText('Uses current records: licenses created by this date whose current expiry is after it. Later renewals can affect selection; newer, lifetime and revoked licenses are excluded.'),
            Text::make(function (Get $get): string {
                $product = Product::whereKey($get('product_id'))->first();
                if ($product === null) {
                    return 'Select product to preview.';
                }
                $eligibleAt = CarbonImmutable::now('UTC');
                if ($get('targets') === 'incident') {
                    if (Validator::make(['eligible_at' => $get('eligible_at')], ['eligible_at' => ['required', 'date', 'before_or_equal:'.$eligibleAt->format('Y-m-d H:i:s')]])->fails()) {
                        return 'Select an incident start date at or before now to preview.';
                    }
                    $eligibleAt = CarbonImmutable::parse($get('eligible_at'), 'UTC');
                }
                $count = app(ManageOutages::class)->preview($product, $eligibleAt, self::actor());

                return 'Currently eligible: '.$count.'. Count is rechecked on confirmation.';
            }),
            TextInput::make('reference')->default(fn (): string => (string) Str::uuid())->required()->maxLength(128),
            Textarea::make('reason')->required()->maxLength(2000),
        ])->action(function (array $data): void {
            $eligibleAt = $data['targets'] === 'incident' ? CarbonImmutable::parse($data['eligible_at'], 'UTC') : CarbonImmutable::now('UTC');
            $operation = app(ManageOutages::class)->addTime(Product::whereKey($data['product_id'])->firstOrFail(), (int) $data['duration'] * (int) $data['unit'], $eligibleAt, $data['reference'], $data['reason'], self::actor());
            Notification::make()->title('Added time queued for '.$operation->target_count.' licenses')->success()->send();
        });
    }
}
