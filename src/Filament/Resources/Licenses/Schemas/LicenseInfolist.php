<?php

namespace LicenseKit\Filament\Resources\Licenses\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use LicenseKit\Core\Actions\DeviceBans;
use LicenseKit\Core\Actions\LicensedDevices;
use LicenseKit\Core\Models\ActivityEntry;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LoaderSession;
use LicenseKit\Filament\Enums\LicenseState;
use LicenseKit\Filament\Resources\ActivityLog\ActivityEntryResource;
use LicenseKit\Filament\Support\PcHistory;
use LicenseKit\Filament\Support\TimeLeft;

/** The license page: who, what state, which PC, which keys and what happened. */
class LicenseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(['md' => 3])->components([
            Section::make('Overview')->icon(Heroicon::OutlinedIdentification)->columnSpanFull()->columns(['md' => 4])->schema([
                TextEntry::make('customer')->label('Customer')->weight(FontWeight::Medium)->copyable()
                    ->state(fn (License $record): string => $record->user?->getAttribute(config('licensekit.user_label')) ?? 'Deleted customer'),
                TextEntry::make('product.name')->label('Product'),
                TextEntry::make('state')->label('Status')->badge()->state(fn (License $record): LicenseState => LicenseState::for($record)),
                TextEntry::make('ends')->label('Ends')->state(fn (License $record): string => TimeLeft::date($record->expires_at))
                    ->visible(fn (License $record): bool => $record->expires_at !== null),
                TextEntry::make('suspended_until')->label('Suspended until')
                    ->state(fn (License $record): string => $record->suspended_until === null ? 'Until you lift it' : TimeLeft::date($record->suspended_until))
                    ->visible(fn (License $record): bool => $record->isSuspended()),
                TextEntry::make('paused_until')->label('Resumes automatically')->state(fn (License $record): string => TimeLeft::date($record->paused_until))
                    ->visible(fn (License $record): bool => $record->paused_at !== null && $record->paused_until !== null),
                TextEntry::make('note')->label('Note')->columnSpanFull()->visible(fn (License $record): bool => filled($record->note)),
            ]),
            Section::make('PC lock')->icon(Heroicon::OutlinedLockClosed)->columnSpan(['md' => 2])->columns(['md' => 2])
                ->description(fn (License $record): ?string => $record->product->device_lock ? null : 'This product does not lock licenses to a PC.')
                ->schema([
                    TextEntry::make('device_state')->label('Lock')->badge()
                        ->state(fn (License $record): string => $record->device === null ? 'Not locked yet' : 'Locked to a PC')
                        ->color(fn (License $record): string => $record->device === null ? 'gray' : 'success')
                        ->icon(fn (License $record): Heroicon => $record->device === null ? Heroicon::OutlinedLockOpen : Heroicon::OutlinedLockClosed),
                    TextEntry::make('device_bound_at')->label('Locked since')->state(fn (License $record): string => TimeLeft::date($record->device_bound_at))
                        ->placeholder('Locks on the next app login'),
                    TextEntry::make('move_rule')->label('Customer can move it')->state(fn (License $record): string => self::moveRule($record)),
                    TextEntry::make('device_reset_at')->label('Last moved or reset')->state(fn (License $record): string => TimeLeft::date($record->device_reset_at))
                        ->placeholder('Never'),
                    TextEntry::make('frequent')->label('Changes PC often')->columnSpanFull()->color('warning')->icon(Heroicon::OutlinedExclamationTriangle)
                        ->state(fn (License $record): string => PcHistory::recentChanges($record).' moves or resets in the last '.PcHistory::WINDOW_DAYS.' days.')
                        ->helperText('Frequent changes can mean the key is being shared. Check the PC history below.')
                        ->visible(fn (License $record): bool => PcHistory::recentChanges($record) >= PcHistory::FREQUENT_CHANGES),
                    TextEntry::make('shared')->label('Shared PC warning')->columnSpanFull()->color('danger')->icon(Heroicon::OutlinedExclamationTriangle)
                        ->state(fn (License $record): array => self::sharedWith($record))->listWithLineBreaks()
                        ->helperText('Other accounts logged in from this PC. It may be a family PC, or one person with several accounts.')
                        ->visible(fn (License $record): bool => self::sharedWith($record) !== []),
                ]),
            Section::make('Online now')->icon(Heroicon::OutlinedSignal)->columnSpan(['md' => 1])->schema([
                TextEntry::make('sessions')->hiddenLabel()->listWithLineBreaks()->bulleted()->placeholder('Not using your app right now.')
                    ->state(fn (License $record): array => LoaderSession::query()->where('user_id', $record->user_id)->where('product_id', $record->product_id)
                        ->where('expires_at', '>', now())->latest('last_seen_at')->limit(5)->pluck('last_seen_at')
                        ->map(fn ($seen): string => 'App open, last check-in '.$seen->diffForHumans())->all()),
            ]),
            Section::make('PC history')->icon(Heroicon::OutlinedComputerDesktop)->columnSpanFull()->collapsible()
                ->description('Every lock, move and reset. The same PC always gets the same short name.')
                ->schema([
                    RepeatableEntry::make('pc_history')->label('PC history')->hiddenLabel()->placeholder('No PC changes yet.')
                        ->state(fn (License $record): array => PcHistory::for($record))
                        ->table([TableColumn::make('When'), TableColumn::make('What'), TableColumn::make('By'), TableColumn::make('PC'), TableColumn::make('Note')])
                        ->schema([
                            TextEntry::make('when'),
                            TextEntry::make('what')->weight(FontWeight::Medium)->color(fn (string $state): string => match ($state) {
                                'Locked on first login' => 'gray', 'Reset by staff' => 'info', default => 'warning',
                            }),
                            TextEntry::make('who'),
                            TextEntry::make('pc')->fontFamily(FontFamily::Mono),
                            TextEntry::make('note')->placeholder('—'),
                        ]),
                ]),
            Section::make('Keys redeemed')->icon(Heroicon::OutlinedKey)->columnSpanFull()->schema([
                RepeatableEntry::make('keys')->hiddenLabel()->placeholder('No keys redeemed yet.')
                    ->table([TableColumn::make('Key'), TableColumn::make('Batch'), TableColumn::make('Sold by'), TableColumn::make('Redeemed')])
                    ->schema([
                        TextEntry::make('key')->fontFamily(FontFamily::Mono)->copyable()->copyMessage('Key copied'),
                        TextEntry::make('batch_id')->formatStateUsing(fn ($state, LicenseKey $record): string => $record->batch->label ?? 'Batch #'.$state),
                        TextEntry::make('batch.reseller.'.config('licensekit.user_label'))->placeholder('Your shop'),
                        TextEntry::make('redeemed_at')->since()->dateTimeTooltip(timezone: config('app.timezone')),
                    ]),
            ]),
            Section::make('Activity')->icon(Heroicon::OutlinedClock)->columnSpanFull()->collapsible()
                ->description(fn (License $record): string => 'The latest changes. See everything in the activity log.')
                ->schema([
                    TextEntry::make('activity')->hiddenLabel()->listWithLineBreaks()->bulleted()->placeholder('No activity yet.')
                        ->state(fn (License $record): array => ActivityEntry::query()->where('subject_type', $record->getMorphClass())->where('subject_id', $record->id)
                            ->latest('id')->limit(20)->get()->map(fn (ActivityEntry $entry): string => $entry->summary().', '.$entry->created_at->diffForHumans())->all()),
                    TextEntry::make('all_activity')->hiddenLabel()->state('Open the activity log')->color('primary')
                        ->url(fn (License $record): string => ActivityEntryResource::getUrl('index', ['filters' => ['license' => ['license_id' => $record->id]]])),
                ]),
        ]);
    }

    private static function moveRule(License $license): string
    {
        $days = $license->product->device_move_cooldown_days;
        if ($days === null) {
            return 'No, only your team can';
        }
        $rule = $days === 0 ? 'Any time' : 'Once every '.$days.' '.($days === 1 ? 'day' : 'days');
        $next = app(LicensedDevices::class)->eligibility($license)['available_at'];

        return $next !== null && now()->lessThan($next) ? $rule.', next from '.TimeLeft::date(now()->parse($next)) : $rule;
    }

    /** @return array<int, string> */
    private static function sharedWith(License $license): array
    {
        if ($license->device === null) {
            return [];
        }

        return app(DeviceBans::class)->sharedAccounts($license)
            ->map(fn (License $other): string => ($other->user?->getAttribute(config('licensekit.user_label')) ?? 'Deleted customer').' · '.$other->product->name)
            ->unique()->values()->all();
    }
}
