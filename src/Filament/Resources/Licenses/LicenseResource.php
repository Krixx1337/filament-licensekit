<?php

namespace LicenseKit\Filament\Resources\Licenses;

use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Models\License;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Enums\LicenseState;
use LicenseKit\Filament\Resources\Licenses\Pages\ManageLicenses;
use LicenseKit\Filament\Resources\Licenses\Pages\ViewLicense;
use LicenseKit\Filament\Resources\Licenses\Schemas\LicenseInfolist;
use LicenseKit\Filament\Resources\Licenses\Tables\LicensesTable;
use LicenseKit\Filament\Resources\LicensingResource;
use LicenseKit\Filament\Support\TimeLeft;

class LicenseResource extends LicensingResource
{
    protected static ?int $navigationSort = 20;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static ?string $slug = 'licensekit/licenses';

    protected static ?string $model = License::class;

    protected static string $access = AdminAccess::SUPPORT;

    protected static ?string $navigationLabel = 'Licenses';

    protected static ?string $modelLabel = 'license';

    protected static ?string $pluralModelLabel = 'Licenses';

    protected static bool $editable = false;

    /** @return Builder<Model> */
    public static function getEloquentQuery(): Builder
    {
        return AdminAccess::scope(parent::getEloquentQuery())->with(['user', 'product'])->withCount('keys');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return LicensesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return LicenseInfolist::configure($schema);
    }

    /** @return Builder<Model> */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['user', 'product']);
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return $record instanceof License ? ['Status' => LicenseState::for($record)->getLabel(), 'Time left' => TimeLeft::for($record)] : [];
    }

    public static function getNavigationBadge(): ?string
    {
        $count = AdminAccess::scope(License::query())->effectiveStatus('expiring')->count();

        return $count === 0 ? null : (string) $count;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return 'Expiring in the next '.LicenseState::EXPIRING_DAYS.' days';
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        return $record->getRelationValue('user')?->getAttribute(config('licensekit.user_label')).' / '.$record->getRelationValue('product')?->getAttribute('name');
    }

    public static function getPages(): array
    {
        return ['index' => ManageLicenses::route('/'), 'view' => ViewLicense::route('/{record}')];
    }
}
