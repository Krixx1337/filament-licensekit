<?php

namespace LicenseKit\Filament\Resources\LicenseKeys;

use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Resources\LicenseKeys\Pages\ManageLicenseKeys;
use LicenseKit\Filament\Resources\LicensingResource;

class LicenseKeyResource extends LicensingResource
{
    protected static ?string $slug = 'licensekit/license-keys';

    protected static ?string $model = LicenseKey::class;

    protected static string $access = AdminAccess::SUPPORT;

    protected static ?string $navigationLabel = 'Keys';

    protected static ?string $modelLabel = 'key';

    protected static ?string $pluralModelLabel = 'Keys';

    protected static bool $editable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('key')->searchable()->copyable()->toggleable(),
            TextColumn::make('plan.name')->label('Plan')->searchable()->toggleable(),
            TextColumn::make('batch_id')->label('Batch')->formatStateUsing(fn ($state, LicenseKey $record): string => 'Batch #'.$state.($record->batch->label !== null ? ' · '.$record->batch->label : ''))->toggleable(),
            TextColumn::make('batch.label')->label('Batch label')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('note')->limit(30)->wrap()->toggleable(),
            TextColumn::make('entitlement_id')->label('License')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('claimed_at')->label('Given at')->dateTime()->toggleable(),
            TextColumn::make('revoked_at')->label('Revoked at')->dateTime()->toggleable()])->filters([
                SelectFilter::make('batch_id')->label('Batch')->relationship('batch', 'id')->getOptionLabelFromRecordUsing(fn (Batch $record): string => 'Batch #'.$record->id.($record->label !== null ? ' · '.$record->label : ''))->searchable(),
            ])->recordActions([LicensingActions::claim(), LicensingActions::editNote(),
                LicensingActions::revokeKey()])->defaultSort('id', 'desc');
    }

    /** @return Builder<LicenseKey> */
    public static function getEloquentQuery(): Builder
    {
        return LicenseKey::query()->with(['plan', 'batch']);
    }

    public static function getPages(): array
    {
        return ['index' => ManageLicenseKeys::route('/')];
    }
}
