<?php

namespace LicenseKit\Filament\Actions;

use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\DeviceBans;
use LicenseKit\Core\Actions\Keys;
use LicenseKit\Core\Actions\LicensedDevices;
use LicenseKit\Core\Actions\LicenseDuration;
use LicenseKit\Core\Actions\ManageKeys;
use LicenseKit\Core\Actions\ManageLicenses;
use LicenseKit\Core\Data\IssueContext;
use LicenseKit\Core\Models\ApiConnection;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Core\Users\ConfiguredUserModel;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Support\TimeLeft;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LicensingActions
{
    public static function mayChange(Model $record, bool $support = false): bool
    {
        if (! ($support ? AdminAccess::isSupport() : AdminAccess::isAdmin())) {
            return false;
        }
        $gate = Gate::forUser(self::actor());

        return $gate->getPolicyFor($record) === null || $gate->allows('update', $record);
    }

    public static function actor(): Model
    {
        return AdminAccess::actor();
    }

    /**
     * The license "Manage" menu, split into time, PC, note and access sections. Add time sits outside it as the main button.
     *
     * @return list<ActionGroup>
     */
    public static function licenseMenu(): array
    {
        return [
            ActionGroup::make([self::pauseLicense(), self::resumeLicense()])->dropdown(false),
            ActionGroup::make([self::reset(), self::inspectDevice(), self::banDevice()])->dropdown(false),
            ActionGroup::make([self::licenseNote()])->dropdown(false),
            ActionGroup::make([self::suspend(), self::unsuspend(), self::cancelLicense(), self::restoreLicense()])->dropdown(false),
        ];
    }

    /** Refreshes the shown license and confirms what happened, naming the customer. */
    private static function finish(Action $action, Closure $message): Action
    {
        return $action->after(function (License $record, array $data) use ($message): void {
            $record->refresh();
            Notification::make()->title($message(self::who($record), $data))->success()->send();
        });
    }

    private static function who(License $license): string
    {
        return ($license->user?->getAttribute(config('licensekit.user_label')) ?? 'Deleted customer').' · '.$license->product->name;
    }

    public static function licenseNote(): Action
    {
        return self::finish(Action::make('license_note')->label('Edit note')->icon(Heroicon::OutlinedPencilSquare)
            ->authorize(fn (License $record) => self::mayChange($record, true))
            ->schema([Textarea::make('note')->label('Note')->helperText('Only your team sees this.')->maxLength(2000)])->fillForm(fn (License $record) => ['note' => $record->note])
            ->action(fn (License $record, array $data) => app(ManageLicenses::class)->updateNote($record, self::actor(), $data['note'] ?? null)),
            fn (string $who): string => 'Note saved for '.$who);
    }

    public static function editNote(): Action
    {
        return Action::make('edit_note')->label('Edit note')->authorize(fn (LicenseKey $record): bool => self::mayChange($record))
            ->fillForm(fn (LicenseKey $record): array => ['note' => $record->getAttribute('note')])->schema([TextInput::make('note')->maxLength(255)])
            ->action(fn (LicenseKey $record, array $data) => app(ManageKeys::class)->updateNote($record, self::actor(), $data['note'] ?? null));
    }

    public static function exportBatch(): Action
    {
        return Action::make('export_unused')->label('Download unused keys')->authorize(fn (): bool => AdminAccess::isAdmin())
            ->action(function (Batch $record): ?StreamedResponse {
                $keys = app(ManageKeys::class)->exportUnusedBatch($record);
                if ($keys === []) {
                    Notification::make()->title('No unused keys to download')->warning()->send();

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
        return Action::make('cancel_unused')->label('Cancel unused keys')->color('danger')->requiresConfirmation()
            ->modalDescription('Cancels unused keys. Customers who already redeemed keep their time.')
            ->authorize(fn (Batch $record): bool => self::mayChange($record))
            ->schema([Textarea::make('reason')->maxLength(2000)])
            ->action(function (Batch $record, array $data): void {
                $count = app(ManageKeys::class)->cancelUnusedBatch($record, self::actor(), ($data['reason'] ?? null));
                Notification::make()->title('Cancelled '.$count.' unused keys in this key batch')->success()->send();
            });
    }

    public static function createKeys(): Action
    {
        return Action::make('create_keys')->label('Generate keys')->authorize(fn (): bool => AdminAccess::isAdmin())
            ->schema([
                Select::make('product_id')->label('Product')->options(fn () => Product::pluck('name', 'id'))->required()->live()
                    ->default(fn (?Model $record) => $record instanceof Product ? $record->id : ($record instanceof LicensePlan ? $record->product_id : null))
                    ->afterStateUpdated(fn (Set $set) => $set('plan_id', null)),
                Select::make('plan_id')->label('Plan')->options(fn (Get $get) => LicensePlan::where('product_id', $get('product_id'))->where('is_active', true)->pluck('name', 'id'))
                    ->default(fn (?Model $record) => $record instanceof LicensePlan ? $record->id : null)->required(),
                TextInput::make('quantity')->label('How many')->integer()->minValue(1)->maxValue(config('licensekit.max_batch_size'))->default(1)->required(),
                TextInput::make('label')->label('Label (optional)')->maxLength(255),
                Select::make('connection_id')->label('Store connection (optional)')->options(fn () => ApiConnection::where('is_active', true)->pluck('name', 'id')),
            ])->action(function (array $data, $livewire): void {
                $plan = LicensePlan::where('product_id', $data['product_id'])->whereKey($data['plan_id'])->firstOrFail();
                $connection = empty($data['connection_id']) ? null : ApiConnection::whereKey($data['connection_id'])->firstOrFail();
                $batch = app(Keys::class)->issue($plan, (int) $data['quantity'], new IssueContext(source: 'admin', actor: self::actor(), connection: $connection, label: $data['label'] ?? null));
                $livewire->replaceMountedAction('result', ['text' => $batch->keys->pluck('key')->implode("\n")]);
            });
    }

    public static function giveLicense(): Action
    {
        return Action::make('give_license')->label('Give license')->authorize(fn (): bool => AdminAccess::isAdmin())
            ->schema([self::userSelect()->label('Customer'),
                Select::make('product_id')->label('Product')->options(fn () => Product::pluck('name', 'id'))->live()->required(),
                Select::make('plan_id')->label('Plan')->options(fn (Get $get) => LicensePlan::where('product_id', $get('product_id'))->where('is_active', true)->pluck('name', 'id'))
                    ->requiredWithout('custom_days'),
                TextInput::make('custom_days')->label('Custom days (optional)')->integer()->minValue(1)->maxValue(LicenseDuration::MAXIMUM_DAYS),
            ])->action(function (array $data): void {
                DB::transaction(function () use ($data): void {
                    $plan = LicensePlan::where('product_id', $data['product_id'])->where('is_active', true)
                        ->when(! empty($data['plan_id']), fn ($q) => $q->whereKey($data['plan_id']))
                        ->firstOrFail();
                    $batch = app(Keys::class)->issue($plan, 1, new IssueContext(source: 'admin', actor: self::actor(), customDays: empty($data['custom_days']) ? null : (int) $data['custom_days']));
                    app(ClaimLicense::class)->claimFor(self::actor(), ConfiguredUserModel::className()::findOrFail($data['user_id']), $batch->keys->firstOrFail());
                });
                Notification::make()->title('License given')->success()->send();
            });
    }

    public static function banDevice(): Action
    {
        return self::finish(Action::make('ban_device')->label('Ban this PC')->icon(Heroicon::OutlinedNoSymbol)->color('danger')->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedNoSymbol)->modalHeading('Ban this PC')
            ->modalDescription('This PC can no longer log in, even with another account. Use it for cheaters and chargebacks.')
            ->authorize(fn (License $record): bool => self::mayChange($record))->visible(fn (License $record): bool => $record->device !== null)
            ->schema([
                Text::make(function (License $record): string {
                    $others = app(DeviceBans::class)->sharedAccounts($record);

                    if ($others->isEmpty()) {
                        return 'No other accounts have used this PC.';
                    }

                    return 'Shared PC warning: also used by '.$others->unique('user_id')->count().' other accounts: '.$others->map(fn ($license) => $license->user
                        ?->getAttribute(config('licensekit.user_label')))->unique()->implode(', ');
                }),
                Toggle::make('all_products')->label('Ban across all products')->default(false),
                Toggle::make('suspend')->label('Also suspend this license')->default(true),
                Select::make('also_suspend')->label('Also suspend these shared licenses')->multiple()->default([])
                    ->options(fn (License $record) => app(DeviceBans::class)->sharedAccounts($record)->mapWithKeys(fn ($license) => [$license->id => $license->user
                        ?->getAttribute(config('licensekit.user_label')).' / '.$license->product->name])->all()),
                Textarea::make('note')->label('Note (optional)')->maxLength(2000),
            ])->action(fn (License $record, array $data) => app(DeviceBans::class)->ban($record, self::actor(),
                $data['all_products'] ?? false, $data['suspend'] ?? true, array_values(array_map('intval', $data['also_suspend'] ?? [])), $data['note'] ?? null)),
            fn (string $who): string => 'PC banned for '.$who);
    }

    public static function userSelect(string $name = 'user_id'): Select
    {
        return Select::make($name)->options(fn (): array => ConfiguredUserModel::className()::query()->limit(50)->pluck(config('licensekit.user_label'),
            (new (ConfiguredUserModel::className()))->getKeyName())->all())->searchable()
            ->getSearchResultsUsing(fn (string $search): array => ConfiguredUserModel::className()::query()->where(config('licensekit.user_label'), 'like', '%'.$search.'%')
                ->limit(50)->pluck(config('licensekit.user_label'), (new (ConfiguredUserModel::className()))->getKeyName())->all())
            ->getOptionLabelUsing(fn ($value) => ConfiguredUserModel::className()::query()->whereKey($value)->first()?->getAttribute(config('licensekit.user_label')))->required();
    }

    public static function claim(): Action
    {
        return Action::make('claim')->authorize(fn (): bool => AdminAccess::isAdmin())->label('Redeem for customer')->visible(fn (LicenseKey $record): bool => $record
            ->redeemed_at === null && $record->cancelled_at === null)
            ->schema([self::userSelect()])
            ->action(function (LicenseKey $record, array $data): void {
                app(ClaimLicense::class)->claimFor(self::actor(), ConfiguredUserModel::className()::findOrFail($data['user_id']), $record);
            });
    }

    public static function revokeKey(): Action
    {
        return Action::make('revoke_key')->label('Cancel key')->requiresConfirmation()
            ->modalDescription('Cancels this unused key. Existing licenses keep their time.')
            ->authorize(fn (LicenseKey $record): bool => self::mayChange($record))->color('danger')->visible(fn (LicenseKey $record): bool => $record
            ->redeemed_at === null && $record->cancelled_at === null)
            ->schema([Textarea::make('reason')->maxLength(2000)])
            ->action(fn (LicenseKey $record, array $data) => app(ManageKeys::class)->revokeKey($record, self::actor(), ($data['reason'] ?? null)));
    }

    public static function suspend(): Action
    {
        return self::finish(Action::make('suspend')->label('Suspend')->icon(Heroicon::OutlinedNoSymbol)->color('danger')->modalIcon(Heroicon::OutlinedNoSymbol)
            ->authorize(fn (License $record): bool => self::mayChange($record, true))
            ->visible(fn (License $record): bool => ! $record->isSuspended() && ! $record->isRevoked())
            ->modalDescription('The customer can no longer use this product or redeem keys for it. Their time keeps running.')
            ->schema(self::suspensionFields())
            ->action(fn (License $record, array $data) => app(ManageLicenses::class)->suspend($record, self::actor(), $data['reason'] ?? null, self::suspensionEnd($data))),
            fn (string $who): string => 'Suspended '.$who);
    }

    public static function unsuspend(): Action
    {
        return self::finish(Action::make('unsuspend')->label('Unsuspend')->icon(Heroicon::OutlinedCheckCircle)->color('success')
            ->authorize(fn (License $record): bool => self::mayChange($record, true))
            ->visible(fn (License $record): bool => $record->isSuspended())
            ->schema([Textarea::make('reason')->label('Note (optional)')->maxLength(2000)])
            ->action(fn (License $record, array $data) => app(ManageLicenses::class)->unsuspend($record, self::actor(), $data['reason'] ?? null)),
            fn (string $who): string => 'Unsuspended '.$who);
    }

    public static function cancelLicense(): Action
    {
        return self::finish(Action::make('cancel')->label('Cancel license')->icon(Heroicon::OutlinedXCircle)->color('danger')->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedXCircle)
            ->authorize(fn (License $record): bool => self::mayChange($record))
            ->visible(fn (License $record): bool => ! $record->isRevoked())
            ->modalDescription('Use this after a refund. Paid access stops and new keys cannot renew this license.')
            ->schema([Textarea::make('reason')->label('Note (optional)')->maxLength(2000)])
            ->action(fn (License $record, array $data) => app(ManageLicenses::class)->revoke($record, self::actor(), $data['reason'] ?? null)),
            fn (string $who): string => 'Cancelled '.$who);
    }

    public static function restoreLicense(): Action
    {
        return self::finish(Action::make('restore')->label('Restore license')->icon(Heroicon::OutlinedArrowUturnLeft)
            ->authorize(fn (License $record): bool => self::mayChange($record))
            ->visible(fn (License $record): bool => $record->isRevoked())
            ->schema([Textarea::make('reason')->label('Note (optional)')->maxLength(2000)])
            ->action(fn (License $record, array $data) => app(ManageLicenses::class)->restore($record, self::actor(), $data['reason'] ?? null)),
            fn (string $who): string => 'Restored '.$who);
    }

    /** Header action for customers who may not have a license yet, such as a ban before first purchase. */
    public static function suspendCustomer(): Action
    {
        return Action::make('suspend_customer')->label('Suspend a customer')->color('danger')
            ->authorize(fn (): bool => AdminAccess::isSupport())
            ->modalDescription('Blocks one customer from one product, even if they have not bought it yet.')
            ->schema([self::userSelect()->label('Customer'), Select::make('product_id')->label('Product')->required()
                ->options(fn (): array => Product::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(), ...self::suspensionFields()])
            ->action(fn (array $data) => app(ManageLicenses::class)->suspendCustomer(ConfiguredUserModel::className()::findOrFail($data['user_id']),
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

    public static function extendLicense(): Action
    {
        return self::finish(Action::make('extend')->label('Add time')->icon(Heroicon::OutlinedPlusCircle)->modalHeading('Add time')->modalSubmitActionLabel('Add time')
            ->authorize(fn (License $record): bool => self::mayChange($record, true))
            ->visible(fn (License $record): bool => $record->user_id !== null && ! $record->is_lifetime && ! $record->isRevoked())
            ->modalDescription('Adds time from the current expiry, or from now if expired. Paused licenses keep the added time banked.')
            ->schema([
                TextInput::make('duration')->label('How much')->integer()->minValue(1)->maxValue(fn (Get $get): int => $get('unit') === 'days' ? 3650 : 87600)->default(1)->required(),
                Select::make('unit')->options(['hours' => 'Hours', 'days' => 'Days'])->default('days')->live()->required(),
                Textarea::make('reason')->label('Note (optional)')->maxLength(2000),
            ])
            ->action(function (License $record, array $data): void {
                app(ManageLicenses::class)->extend($record, self::seconds($data), self::actor(), ($data['reason'] ?? null));
            }),
            fn (string $who, array $data): string => 'Added '.TimeLeft::duration(self::seconds($data)).' to '.$who);
    }

    /** @param array<string, mixed> $data */
    private static function seconds(array $data): int
    {
        return (int) $data['duration'] * ($data['unit'] === 'days' ? 86400 : 3600);
    }

    public static function pauseLicense(): Action
    {
        return self::finish(Action::make('pause')->label('Pause')->icon(Heroicon::OutlinedPauseCircle)
            ->authorize(fn (License $record): bool => self::mayChange($record, true))
            ->visible(fn (License $record): bool => $record->user_id !== null && ! $record->is_lifetime && $record->status === License::ACTIVE && $record->paused_at === null
                && (bool) $record->expires_at?->isFuture())
            ->modalDescription('Stops access and saves the remaining time until you resume it.')
            ->schema([DateTimePicker::make('paused_until')->label('Resume automatically on (optional)')->seconds(false)->minDate(fn () => now()),
                Textarea::make('reason')->label('Note (optional)')->maxLength(2000)])
            ->action(fn (License $record, array $data) => app(ManageLicenses::class)->pause($record, self::actor(), $data['reason'] ?? null,
                empty($data['paused_until']) ? null : CarbonImmutable::parse($data['paused_until'], config('app.timezone'))->utc())),
            fn (string $who): string => 'Paused '.$who.'. Their remaining time is saved.');
    }

    public static function resumeLicense(): Action
    {
        return self::finish(Action::make('resume')->label('Resume')->icon(Heroicon::OutlinedPlayCircle)->color('success')
            ->authorize(fn (License $record): bool => self::mayChange($record, true))
            ->visible(fn (License $record): bool => $record->user_id !== null && ! $record->is_lifetime && $record->status === License::PAUSED)
            ->modalDescription('The saved time starts counting down again from now.')
            ->schema([Textarea::make('reason')->label('Note (optional)')->maxLength(2000)])
            ->action(fn (License $record, array $data) => app(ManageLicenses::class)->resume($record, self::actor(), ($data['reason'] ?? null))),
            fn (string $who): string => 'Resumed '.$who);
    }

    public static function reset(): Action
    {
        return self::finish(Action::make('reset_hwid')->label('Reset PC')->icon(Heroicon::OutlinedArrowPath)->modalHeading('Reset PC')->modalSubmitActionLabel('Reset PC')
            ->authorize(fn (License $record): bool => $record->user !== null && self::mayChange($record, true))
            ->visible(fn (License $record): bool => $record->device !== null)
            ->modalDescription('Unlocks the license from the current PC right away, ignoring the waiting period. The customer\'s next login locks their new PC.')
            ->schema([Textarea::make('reason')->label('Note (optional)')->maxLength(2000)])
            ->action(fn (License $record, array $data) => app(LicensedDevices::class)->adminReset($record->user, $record->product, self::actor(), ($data['reason'] ?? null))),
            fn (string $who): string => 'PC reset for '.$who.'. Their next login locks the new PC.');
    }

    public static function inspectDevice(): Action
    {
        return Action::make('inspect_device')->label('Show PC fingerprint')->icon(Heroicon::OutlinedFingerPrint)
            ->authorize(fn (License $record): bool => $record->user !== null && AdminAccess::isSupport())
            ->visible(fn (License $record): bool => $record->device !== null)
            ->schema([
                Textarea::make('device')->label('The fingerprint your app sent for this PC')->default(function (License $record): ?string {
                    abort_unless(AdminAccess::isSupport(), 403);

                    return $record->device;
                })->rows(6)->disabled()->dehydrated(false),
            ])->modalSubmitAction(false)->modalCancelActionLabel('Close');
    }

    public static function addTime(): Action
    {
        return Action::make('add_time')->label('Add time')
            ->authorize(fn (): bool => AdminAccess::isAdmin())
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
                    $count = app(ManageLicenses::class)->previewAddTimeToProduct($record, $since);

                    return 'This will add time to '.$count.' '.($count === 1 ? 'license' : 'licenses').'.';
                }),
                Textarea::make('reason')->label('Note (optional)')->maxLength(2000),
            ])
            ->action(function (Product $record, array $data): void {
                $since = self::addTimeSince($data['include_expired'] ?? false, $data['since'] ?? null);
                $seconds = (int) $data['duration'] * (int) $data['unit'];
                $count = app(ManageLicenses::class)->addTimeToProduct($record, $seconds, self::actor(), $data['reason'] ?? null, $since ?: null);
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
