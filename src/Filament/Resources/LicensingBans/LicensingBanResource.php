<?php

namespace LicenseKit\Filament\Resources\LicensingBans;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use LicenseKit\Core\Actions\ManageLicensingBans;
use LicenseKit\Core\Models\LicensingBan;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Resources\LicensingBans\Pages\ManageLicensingBans as ManageBansPage;
use LicenseKit\Filament\Resources\LicensingResource;

class LicensingBanResource extends LicensingResource
{
    protected static ?string $slug = 'licensekit/bans';

    protected static ?string $model = LicensingBan::class;

    protected static ?string $navigationLabel = 'Bans';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('user.'.config('licensekit.user.display_column'))->label('User')->searchable()->toggleable(),
            TextColumn::make('product.name')->label('Product')->searchable()->toggleable(),
            TextColumn::make('status')->state(fn (LicensingBan $record): string => $record->status())->badge()->toggleable(),
            TextColumn::make('reason')->searchable()->wrap()->toggleable(),
            TextColumn::make('created_by')->label('Banned by')->searchable()->toggleable(),
            TextColumn::make('expires_at')->label('Ends')->dateTime()->placeholder('Permanent')->toggleable(),
            TextColumn::make('created_at')->label('Banned at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('lifted_at')->label('Lifted at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('lifted_by')->label('Lifted by')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('lift_reason')->label('Lift reason')->searchable()->wrap()->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('status')->options(['active' => 'Active', 'expired' => 'Expired', 'lifted' => 'Lifted'])
                ->query(function ($query, array $data) {
                    return match ($data['value'] ?? null) {
                        'active' => $query->active(),
                        'expired' => $query->whereNull('lifted_at')->where('expires_at', '<=', now('UTC')),
                        'lifted' => $query->whereNotNull('lifted_at'),
                        default => $query,
                    };
                }),
            SelectFilter::make('product')->relationship('product', 'name')->searchable(),
        ])->recordActions([
            Action::make('lift')->label('Lift ban')->authorize(fn (LicensingBan $record): bool => AdminAccess::can('lift', $record, static::class))
                ->visible(fn (LicensingBan $record): bool => $record->lifted_at === null)
                ->schema([Textarea::make('reason')->required()->maxLength(2000)])
                ->action(fn (LicensingBan $record, array $data) => app(ManageLicensingBans::class)->lift($record, $data['reason'], AdminAccess::actor())),
        ])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageBansPage::route('/')];
    }
}
