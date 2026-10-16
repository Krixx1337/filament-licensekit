<?php

namespace LicenseKit\Filament\Resources\ActivityLog;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use LicenseKit\Core\Models\ActivityEntry;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Resources\ActivityLog\Pages\ManageActivityLog;
use LicenseKit\Filament\Resources\LicensingResource;

class ActivityEntryResource extends LicensingResource
{
    protected static ?int $navigationSort = 80;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationParentItem = 'Settings';

    protected static ?string $slug = 'licensekit/activity-log';

    protected static ?string $model = ActivityEntry::class;

    protected static string $access = AdminAccess::SUPPORT;

    protected static ?string $navigationLabel = 'Activity log';

    protected static ?string $modelLabel = 'change';

    protected static ?string $pluralModelLabel = 'Change log';

    protected static bool $editable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('description')->label('Activity')->state(fn (ActivityEntry $record) => $record->description()),
            TextColumn::make('actor')->label('Person')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('meta.note')->label('Note')->limit(60),
            TextColumn::make('created_at')->label('When')->since()->dateTimeTooltip()->sortable(),
        ])->filters([
            SelectFilter::make('product_id')->label('Product')->options(fn () => Product::pluck('name', 'id'))
                ->query(fn ($query, array $data) => $query->when($data['value'] ?? null, fn ($q, $id) => $q->where('meta->product_id', $id))),
            Filter::make('license')->schema([TextInput::make('license_id')->integer()->label('License number')])
                ->query(fn ($query, array $data) => $query->when($data['license_id'] ?? null, fn ($q, $id) => $q->where('meta->license_id', $id))),
        ])->recordActions([Action::make('details')->authorize(fn (ActivityEntry $record): bool => static::canView($record))->schema([
            Textarea::make('meta')->label('Details')->default(fn (ActivityEntry $record) => json_encode($record->meta, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))
                ->disabled()->dehydrated(false)->rows(10),
        ])->modalSubmitAction(false)->modalCancelActionLabel('Close')])->emptyStateHeading('No activity yet')->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageActivityLog::route('/')];
    }
}
