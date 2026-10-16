<?php

namespace LicenseKit\Filament\Resources\ActivityLog;

use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use LicenseKit\Core\Models\ActivityEntry;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Navigation;
use LicenseKit\Filament\Resources\ActivityLog\Pages\ManageActivityLog;
use LicenseKit\Filament\Resources\Licenses\LicenseResource;
use LicenseKit\Filament\Resources\LicensingResource;

class ActivityEntryResource extends LicensingResource
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|\UnitEnum|null $navigationGroup = Navigation::PROTECTION;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'licensekit/activity-log';

    protected static ?string $model = ActivityEntry::class;

    protected static string $access = AdminAccess::SUPPORT;

    protected static ?string $navigationLabel = 'Activity log';

    protected static ?string $modelLabel = 'activity';

    protected static ?string $pluralModelLabel = 'Activity log';

    protected static bool $editable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->label('When')->since()->dateTimeTooltip(timezone: config('app.timezone'))->sortable(),
            TextColumn::make('summary')->label('What happened')->state(fn (ActivityEntry $record): string => $record->summary())->wrap()
                ->description(fn (ActivityEntry $record): ?string => $record->meta['note'] ?? null),
            TextColumn::make('subject_id')->label('About')
                ->formatStateUsing(fn ($state, ActivityEntry $record): string => self::subjectLabel($record))
                ->url(fn (ActivityEntry $record): ?string => $record->subject_type === (new License)->getMorphClass() && LicenseResource::canViewAny()
                    ? LicenseResource::getUrl('view', ['record' => $record->subject_id]) : null),
            TextColumn::make('actor')->label('Person')->searchable(['actor', 'meta'])->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('event')->label('Type')->multiple()
                ->options(collect(ActivityEntry::EVENTS)->map(fn (string $verb): string => ucfirst($verb))->sort()->all()),
            SelectFilter::make('product_id')->label('Product')->options(fn () => Product::pluck('name', 'id'))
                ->query(fn ($query, array $data) => $query->when($data['value'] ?? null, fn ($q, $id) => $q->where('meta->product_id', $id))),
            Filter::make('created_at')->label('Date')->schema([
                DatePicker::make('from')->label('From'), DatePicker::make('until')->label('Until'),
            ])->query(fn ($query, array $data) => $query
                ->when($data['from'] ?? null, fn ($q, $date) => $q->where('created_at', '>=', CarbonImmutable::parse($date, config('app.timezone'))->startOfDay()->utc()))
                ->when($data['until'] ?? null, fn ($q, $date) => $q->where('created_at', '<=', CarbonImmutable::parse($date, config('app.timezone'))->endOfDay()->utc())))
                ->indicateUsing(fn (array $data): array => array_filter([
                    'from' => filled($data['from'] ?? null) ? 'From '.CarbonImmutable::parse($data['from'])->toFormattedDateString() : null,
                    'until' => filled($data['until'] ?? null) ? 'Until '.CarbonImmutable::parse($data['until'])->toFormattedDateString() : null,
                ])),
            Filter::make('license')->schema([TextInput::make('license_id')->integer()->label('License number')])
                ->query(fn ($query, array $data) => $query->when($data['license_id'] ?? null, fn ($q, $id) => $q->where(fn ($q) => $q
                    ->where(fn ($q) => $q->where('subject_type', (new License)->getMorphClass())->where('subject_id', (string) $id))->orWhere('meta->license_id', $id))))
                ->indicateUsing(fn (array $data): ?string => filled($data['license_id'] ?? null) ? 'License #'.$data['license_id'] : null),
        ])->recordActions([Action::make('details')->label('Details')->icon(Heroicon::OutlinedEye)->iconButton()
            ->authorize(fn (ActivityEntry $record): bool => static::canView($record))->schema([
                Textarea::make('meta')->label('Everything that was recorded')
                    ->default(fn (ActivityEntry $record) => json_encode($record->meta, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))
                    ->disabled()->dehydrated(false)->rows(10),
            ])->modalSubmitAction(false)->modalCancelActionLabel('Close')])
            ->emptyStateHeading('No activity yet')->emptyStateIcon('heroicon-o-clock')
            ->emptyStateDescription('Every change you, your team, your store or your customers make is listed here.')
            ->persistFiltersInSession()->defaultSort('id', 'desc');
    }

    private static function subjectLabel(ActivityEntry $entry): string
    {
        return match (class_basename($entry->subject_type)) {
            'License' => 'License #'.$entry->subject_id,
            'Batch' => 'Key batch #'.$entry->subject_id,
            'LicenseKey' => 'Key #'.$entry->subject_id,
            'DeviceBan' => 'PC ban #'.$entry->subject_id,
            'ApiConnection' => 'Store connection #'.$entry->subject_id,
            default => class_basename($entry->subject_type).' #'.$entry->subject_id,
        };
    }

    public static function getPages(): array
    {
        return ['index' => ManageActivityLog::route('/')];
    }
}
