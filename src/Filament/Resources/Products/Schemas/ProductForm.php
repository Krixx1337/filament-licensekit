<?php

namespace LicenseKit\Filament\Resources\Products\Schemas;

use Carbon\CarbonImmutable;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\Enums\ProductStatus;

/** Product form: name and plans on the left, status, PC lock and free weekend on the right. */
class ProductForm
{
    private const PRESETS = ['1' => '1 day', '7' => '7 days', '30' => '30 days', '90' => '90 days', '365' => '1 year', 'lifetime' => 'Lifetime', 'custom' => 'Custom'];

    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(['lg' => 3])->components([
            Group::make([
                Section::make('Product')->icon(Heroicon::OutlinedCube)->schema([
                    TextInput::make('name')->label('Name')->required()->maxLength(255)->placeholder('For example: Frame Booster')
                        ->live(onBlur: true)->afterStateUpdated(function ($state, Set $set, ?Model $record): void {
                            if ($record === null) {
                                $set('slug', Str::slug((string) $state));
                            }
                        }),
                    Section::make('Advanced')->collapsed()->compact()->schema([
                        TextInput::make('slug')->label('Web address name')->required()->maxLength(100)->regex('/^[a-z0-9-]+$/')->unique(ignoreRecord: true)
                            ->helperText('Used in your app\'s API address. Lowercase letters, numbers and dashes. Changing it breaks apps that already use the old one.'),
                    ]),
                ]),
                Section::make('Plans')->icon(Heroicon::OutlinedRectangleStack)
                    ->description('What customers can buy. Each key you generate belongs to one plan.')
                    ->schema([
                        Repeater::make('plans')->relationship()->hiddenLabel()->deletable(false)->addActionLabel('Add plan')->defaultItems(1)
                            ->table([TableColumn::make('Plan name'), TableColumn::make('Length'), TableColumn::make('Days')->width('110px'),
                                TableColumn::make('On sale')->width('90px')])
                            ->schema([
                                TextInput::make('name')->label('Plan name')->required()->maxLength(255)->placeholder('Monthly'),
                                Select::make('duration_preset')->label('Length')->options(self::PRESETS)->default('30')->live()->dehydrated(false)->selectablePlaceholder(false)
                                    ->afterStateHydrated(function ($component, ?LicensePlan $record): void {
                                        if ($record !== null) {
                                            $component->state($record->duration_days === null ? 'lifetime'
                                                : (in_array($record->duration_days, [1, 7, 30, 90, 365], true) ? (string) $record->duration_days : 'custom'));
                                        }
                                    })
                                    ->afterStateUpdated(function ($state, Set $set): void {
                                        if ($state !== 'custom') {
                                            $set('duration_days', $state === 'lifetime' ? null : (int) $state);
                                        }
                                    }),
                                TextInput::make('duration_days')->label('Days')->integer()->minValue(1)->maxValue(3650)->default(30)
                                    ->disabled(fn (Get $get): bool => $get('duration_preset') !== 'custom')->dehydrated(),
                                Toggle::make('is_active')->label('On sale')->default(true),
                            ]),
                    ]),
            ])->columnSpan(['lg' => 2]),
            Group::make([
                Section::make('Status')->icon(Heroicon::OutlinedSignal)->schema([
                    Radio::make('status')->hiddenLabel()->options(ProductStatus::class)->default(Product::ACTIVE)->required(),
                ]),
                Section::make('PC lock')->icon(Heroicon::OutlinedLockClosed)->schema([
                    Toggle::make('device_lock')->label('Lock each license to one PC')->default(true)->live()
                        ->helperText('The first PC a customer logs in from is locked to their license. Stops key sharing.'),
                    Select::make('device_move_cooldown_days')->label('Customers can move to a new PC')
                        ->options([0 => 'Any time', 1 => 'Once a day', 7 => 'Once a week', 14 => 'Every 2 weeks', 30 => 'Once a month'])
                        ->placeholder('Never (only your team)')->default(7)->visible(fn (Get $get): bool => (bool) $get('device_lock')),
                ]),
                Section::make('Free weekend')->icon(Heroicon::OutlinedGift)->collapsible()
                    ->collapsed(fn (?Product $record): bool => $record?->free_from === null)
                    ->description('Anyone with an account can use the product during this time, even without a key.')
                    ->schema([
                        DateTimePicker::make('free_from')->label('Starts')->timezone(config('app.timezone'))->seconds(false)->requiredWith('free_until')
                            ->dehydrateStateUsing(fn ($state) => self::utc($state)),
                        DateTimePicker::make('free_until')->label('Ends')->timezone(config('app.timezone'))->seconds(false)->after('free_from')->requiredWith('free_from')
                            ->dehydrateStateUsing(fn ($state) => self::utc($state)),
                    ]),
                Section::make()->hidden(fn (?Product $record): bool => $record === null)->schema([
                    TextEntry::make('created_at')->label('Created')->since(),
                    TextEntry::make('updated_at')->label('Last changed')->since(),
                ]),
            ])->columnSpan(['lg' => 1]),
        ]);
    }

    private static function utc(mixed $state): ?string
    {
        return filled($state) ? CarbonImmutable::parse($state, config('app.timezone'))->utc()->toIso8601String() : null;
    }
}
