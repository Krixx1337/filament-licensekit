<?php

namespace LicenseKit\Filament\Actions;

use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
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

    public static function userSelect(string $name = 'user_id'): Select
    {
        return Select::make($name)->options(fn (): array => ConfiguredUserModel::className()::query()->limit(50)->pluck(config('licensekit.user.display_column'), (new (ConfiguredUserModel::className()))->getKeyName())->all())->searchable()->getSearchResultsUsing(fn (string $search): array => ConfiguredUserModel::className()::query()->where(config('licensekit.user.display_column'), 'like', '%'.$search.'%')->limit(50)->pluck(config('licensekit.user.display_column'), (new (ConfiguredUserModel::className()))->getKeyName())->all())->getOptionLabelUsing(fn ($value) => ConfiguredUserModel::className()::query()->whereKey($value)->first()?->getAttribute(config('licensekit.user.display_column')))->required();
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

    public static function suspend(): Action
    {
        return Action::make('suspend')->label('Suspend')->color('danger')
            ->authorize(fn (Entitlement $record): bool => AdminAccess::can('block', $record))
            ->visible(fn (Entitlement $record): bool => ! $record->isSuspended() && ! $record->isRevoked())
            ->modalDescription('The customer can no longer use this product or redeem keys for it. Their time keeps running.')
            ->schema(self::suspensionFields())
            ->action(fn (Entitlement $record, array $data) => app(ManageEntitlements::class)->suspend($record, self::actor(), $data['reason'] ?? null, self::suspensionEnd($data)));
    }

    public static function unsuspend(): Action
    {
        return Action::make('unsuspend')->label('Unsuspend')
            ->authorize(fn (Entitlement $record): bool => AdminAccess::can('unblock', $record))
            ->visible(fn (Entitlement $record): bool => $record->isSuspended())
            ->schema([Textarea::make('reason')->label('Note (optional)')->maxLength(2000)])
            ->action(fn (Entitlement $record, array $data) => app(ManageEntitlements::class)->unsuspend($record, self::actor(), $data['reason'] ?? null));
    }

    public static function cancelLicense(): Action
    {
        return Action::make('cancel')->label('Cancel license')->color('danger')->requiresConfirmation()
            ->authorize(fn (Entitlement $record): bool => AdminAccess::can('revoke', $record))
            ->visible(fn (Entitlement $record): bool => ! $record->isRevoked())
            ->modalDescription('Use this after a refund. Paid access stops and new keys cannot renew this license.')
            ->schema([Textarea::make('reason')->label('Note (optional)')->maxLength(2000)])
            ->action(fn (Entitlement $record, array $data) => app(ManageEntitlements::class)->revoke($record, self::actor(), $data['reason'] ?? null));
    }

    public static function restoreLicense(): Action
    {
        return Action::make('restore')->label('Restore license')
            ->authorize(fn (Entitlement $record): bool => AdminAccess::can('restoreAccess', $record))
            ->visible(fn (Entitlement $record): bool => $record->isRevoked())
            ->schema([Textarea::make('reason')->label('Note (optional)')->maxLength(2000)])
            ->action(fn (Entitlement $record, array $data) => app(ManageEntitlements::class)->restore($record, self::actor(), $data['reason'] ?? null));
    }

    /** Header action for customers who may not have a license yet, such as a ban before first purchase. */
    public static function suspendCustomer(): Action
    {
        return Action::make('suspend_customer')->label('Suspend a customer')->color('danger')
            ->authorize(fn (): bool => AdminAccess::can('create', Entitlement::class))
            ->modalDescription('Blocks one customer from one product, even if they have not bought it yet.')
            ->schema([self::userSelect()->label('Customer'), Select::make('product_id')->label('Product')->required()
                ->options(fn (): array => Product::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(), ...self::suspensionFields()])
            ->action(fn (array $data) => app(ManageEntitlements::class)->suspendCustomer(ConfiguredUserModel::className()::findOrFail($data['user_id']),
                Product::query()->whereKey($data['product_id'])->firstOrFail(), self::actor(), $data['reason'] ?? null, self::suspensionEnd($data)));
    }

    /** @return list<Field> */
    private static function suspensionFields(): array
    {
        return [
            Textarea::make('reason')->label('Note (optional)')->maxLength(2000),
            DateTimePicker::make('until')->label('Suspend until (optional)')->seconds(false)->minDate(fn () => now())
                ->helperText('Leave empty to suspend until you lift it.'),
        ];
    }

    /** @param array<string, mixed> $data */
    private static function suspensionEnd(array $data): ?CarbonImmutable
    {
        return empty($data['until']) ? null : CarbonImmutable::parse($data['until'], config('app.timezone'))->utc();
    }

    public static function extendEntitlement(): Action
    {
        return Action::make('extend')->label('Extend time')
            ->authorize(fn (Entitlement $record): bool => AdminAccess::can('extend', $record))
            ->visible(fn (Entitlement $record): bool => $record->user_id !== null && ! $record->is_lifetime && ! $record->isRevoked())
            ->modalDescription('Adds time from the current expiry, or from now if expired. Paused licenses keep the added time banked.')
            ->schema([
                TextInput::make('duration')->integer()->minValue(1)->maxValue(fn (Get $get): int => $get('unit') === 'days' ? 3650 : 87600)->default(1)->required(),
                Select::make('unit')->options(['hours' => 'Hours', 'days' => 'Days'])->default('days')->live()->required(),
                Textarea::make('reason')->required()->maxLength(2000),
            ])
            ->action(function (Entitlement $record, array $data): void {
                $seconds = (int) $data['duration'] * ($data['unit'] === 'days' ? 86400 : 3600);
                app(ManageEntitlements::class)->extend($record, $seconds, self::actor(), $data['reason']);
            });
    }

    public static function pauseEntitlement(): Action
    {
        return Action::make('pause')->label('Pause')
            ->authorize(fn (Entitlement $record): bool => AdminAccess::can('pause', $record))
            ->visible(fn (Entitlement $record): bool => $record->user_id !== null && ! $record->is_lifetime && $record->status === Entitlement::ACTIVE && $record->paused_at === null && (bool) $record->expires_at?->isFuture())
            ->modalDescription('Preserves remaining time and stops access until staff resume this license.')
            ->schema([Textarea::make('reason')->required()->maxLength(2000)])
            ->action(fn (Entitlement $record, array $data) => app(ManageEntitlements::class)->pause($record, self::actor(), $data['reason']));
    }

    public static function resumeEntitlement(): Action
    {
        return Action::make('resume')->label('Resume')
            ->authorize(fn (Entitlement $record): bool => AdminAccess::can('resume', $record))
            ->visible(fn (Entitlement $record): bool => $record->user_id !== null && ! $record->is_lifetime && $record->status === Entitlement::PAUSED)
            ->modalDescription('Starts the banked time from now. Other access restrictions still apply.')
            ->schema([Textarea::make('reason')->required()->maxLength(2000)])
            ->action(fn (Entitlement $record, array $data) => app(ManageEntitlements::class)->resume($record, self::actor(), $data['reason']));
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

    public static function addTime(): Action
    {
        return Action::make('add_time')->label('Add time')
            ->authorize(fn (Product $record): bool => AdminAccess::can('addTime', $record))
            ->modalHeading(fn (Product $record): string => 'Add time to '.$record->name)
            ->modalDescription('Use this after downtime or as a goodwill gift. Every running license gets the time added to its end date. Paused licenses keep it for later.')
            ->schema([
                TextInput::make('duration')->label('How much time')->integer()->minValue(1)->default(1)->required()->live(),
                Select::make('unit')->label('Unit')->options(['86400' => 'Days', '3600' => 'Hours'])->default('86400')->required()->live(),
                Toggle::make('include_expired')->label('Also include licenses that expired recently')->default(false)->live(),
                DateTimePicker::make('since')->label('Expired after')->seconds(false)->maxDate(fn () => now())
                    ->required(fn (Get $get): bool => (bool) $get('include_expired'))->visible(fn (Get $get): bool => (bool) $get('include_expired'))->live()
                    ->helperText('Licenses that ran out after this moment get the time too, counted from their old end date.'),
                Text::make(function (Get $get, Product $record): string {
                    $since = self::addTimeSince($get('include_expired'), $get('since'));
                    if ($since === false) {
                        return 'Pick when the downtime started to see how many licenses this affects.';
                    }
                    $count = app(ManageEntitlements::class)->previewAddTimeToProduct($record, $since);

                    return 'This will add time to '.$count.' '.($count === 1 ? 'license' : 'licenses').'.';
                }),
                Textarea::make('reason')->label('Note (optional)')->maxLength(2000),
            ])
            ->action(function (Product $record, array $data): void {
                $since = self::addTimeSince($data['include_expired'] ?? false, $data['since'] ?? null);
                $seconds = (int) $data['duration'] * (int) $data['unit'];
                $count = app(ManageEntitlements::class)->addTimeToProduct($record, $seconds, self::actor(), $data['reason'] ?? null, $since ?: null);
                Notification::make()->title('Added time to '.$count.' '.($count === 1 ? 'license' : 'licenses'))->success()->send();
            });
    }

    /** @return CarbonImmutable|null|false null = only running licenses; false = date still missing */
    private static function addTimeSince(mixed $includeExpired, mixed $since): CarbonImmutable|null|false
    {
        if (! $includeExpired) {
            return null;
        }
        if (! is_string($since) || $since === '') {
            return false;
        }
        $since = CarbonImmutable::parse($since, config('app.timezone'))->utc();

        return $since->isFuture() ? false : $since;
    }
}
