<?php

namespace LicenseKit\Filament\Resources\Products;

use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LicenseKit\Core\Actions\ActivityLog;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Pages\ConnectYourApp;
use LicenseKit\Filament\Resources\LicensingResource;
use LicenseKit\Filament\Resources\Products\Pages\ManageProducts;

class ProductResource extends LicensingResource
{
    protected static ?int $navigationSort = 40;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?string $slug = 'licensekit/products';

    protected static ?string $model = Product::class;

    protected static bool $editable = true;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->required()->maxLength(255)->live(onBlur: true)->afterStateUpdated(function ($state, Set $set, ?Model $record): void {
            if ($record === null) {
                $set('slug', Str::slug($state));
            }
        }),
            Section::make('Advanced')->collapsed()->schema([TextInput::make('slug')->required()->maxLength(100)->regex('/^[a-z0-9-]+$/')->unique(ignoreRecord: true)]),
            Select::make('status')->options(['active' => 'Active', 'maintenance' => 'Maintenance', 'discontinued' => 'Discontinued'])->default('active')->required()->selectablePlaceholder(false)
                ->helperText('Maintenance: the software stops working for everyone and licenses keep their time. Discontinued: no new keys, existing licenses keep working.'),
            Toggle::make('device_lock')->label('Lock each license to one device')->default(true)->live()
                ->helperText('The first PC a customer logs in from is locked to their license. Turn off to allow any PC.'),
            Select::make('device_move_cooldown_days')->label('Customers can move their license to a new PC')
                ->options([0 => 'Any time', 1 => 'Once a day', 7 => 'Once a week', 14 => 'Every 2 weeks', 30 => 'Once a month'])
                ->placeholder('Never (support only)')->default(7)->visible(fn (Get $get): bool => (bool) $get('device_lock')),
            DateTimePicker::make('free_from')->label('Free weekend starts')->timezone(config('app.timezone'))->seconds(false)->requiredWith('free_until')
                ->dehydrateStateUsing(fn ($state) => filled($state) ? CarbonImmutable::parse($state, config('app.timezone'))->utc()->toIso8601String() : null),
            DateTimePicker::make('free_until')->label('Free weekend ends')->timezone(config('app.timezone'))->seconds(false)->after('free_from')->requiredWith('free_from')
                ->dehydrateStateUsing(fn ($state) => filled($state) ? CarbonImmutable::parse($state, config('app.timezone'))->utc()->toIso8601String() : null),
            Repeater::make('plans')->relationship()->deletable(false)->schema([
                TextInput::make('name')->required()->maxLength(255),
                Select::make('duration_preset')->label('Duration')->options(['1' => '1 day', '7' => '7 days', '30' => '30 days', '90' => '90 days', '365' => '1 year',
                    'lifetime' => 'Lifetime', 'custom' => 'Custom'])
                    ->default('30')->live()->dehydrated(false)
                    ->afterStateHydrated(function ($component, ?LicensePlan $record): void {
                        if ($record !== null) {
                            $component->state($record->duration_days === null ? 'lifetime' : (in_array($record->duration_days, [1, 7, 30, 90, 365]) ? (string) $record->duration_days : 'custom'));
                        }
                    })
                    ->afterStateUpdated(function ($state, Set $set): void {
                        if ($state !== 'custom') {
                            $set('duration_days', $state === 'lifetime' ? null : (int) $state);
                        }
                    }),
                TextInput::make('duration_days')->label('Custom days')->integer()->minValue(1)->maxValue(3650)->default(30)
                    ->visible(fn (Get $get): bool => $get('duration_preset') === 'custom')->dehydrated()->dehydratedWhenHidden(),
                Toggle::make('is_active')->label('Active')->default(true),
            ])->columns(2)->defaultItems(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable()->toggleable(),
            TextColumn::make('slug')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('status')->badge()->formatStateUsing(fn (string $state): string => ucfirst($state))
                ->color(fn (string $state): string => match ($state) {
                    'active' => 'success', 'maintenance' => 'warning', default => 'gray'
                })->toggleable()])->recordActions([EditAction::make()->databaseTransaction()->after(fn (Model $record) => app(ActivityLog::class)
            ->record('user:'.AdminAccess::actor()->getKey(), 'product_saved', $record, ['after' => $record->toArray()])),
                    LicensingActions::addTime(), LicensingActions::createKeys(), Action::make('connect')->label('Connect your app')
                        ->url(fn (Product $record) => ConnectYourApp::getUrl(['product' => $record->id]))])->defaultSort('id', 'desc');
    }

    /** @return Builder<Product> */
    public static function getEloquentQuery(): Builder
    {
        return Product::query();
    }

    public static function getPages(): array
    {
        return ['index' => ManageProducts::route('/')];
    }
}
