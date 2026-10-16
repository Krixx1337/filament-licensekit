<?php

namespace LicenseKit\Filament\Resources\AuditEvents;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LicenseKit\Core\Models\AuditEvent;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Resources\AuditEvents\Pages\ManageAuditEvents;
use LicenseKit\Filament\Resources\LicensingResource;

class AuditEventResource extends LicensingResource
{
    protected static ?string $slug = 'licensekit/audit-events';

    protected static ?string $model = AuditEvent::class;

    protected static string $access = AdminAccess::SUPPORT;

    protected static ?string $navigationLabel = 'Change log';

    protected static ?string $modelLabel = 'change';

    protected static ?string $pluralModelLabel = 'Change log';

    protected static bool $editable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('actor')->label('Who')->searchable()->toggleable(),
            TextColumn::make('event')->label('What')->searchable()->toggleable(),
            TextColumn::make('subject_type')->label('Record type')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('subject_id')->label('Record id')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('reason')->limit(60)->toggleable(),
            TextColumn::make('created_at')->label('When')->dateTime()->sortable()->toggleable()])->recordActions([Action::make('details')->authorize(fn (AuditEvent $record): bool => static::canView($record))->schema([
                Textarea::make('before')->default(function (AuditEvent $record): string {
                    abort_unless(static::canView($record), 403);

                    return json_encode($record->before, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
                })->disabled()->dehydrated(false)->rows(8),
                Textarea::make('after')->default(function (AuditEvent $record): string {
                    abort_unless(static::canView($record), 403);

                    return json_encode($record->after, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
                })->disabled()->dehydrated(false)->rows(8),
            ])->modalSubmitAction(false)->modalCancelActionLabel('Close')])->emptyStateHeading('No changes yet')->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageAuditEvents::route('/')];
    }
}
