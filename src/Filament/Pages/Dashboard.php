<?php

namespace LicenseKit\Filament\Pages;

use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Navigation;
use LicenseKit\Filament\Widgets\GetStarted;
use LicenseKit\Filament\Widgets\LicensesByStatusChart;
use LicenseKit\Filament\Widgets\LicenseStats;
use LicenseKit\Filament\Widgets\NeedsAttention;
use LicenseKit\Filament\Widgets\RecentActivity;
use LicenseKit\Filament\Widgets\RedemptionsChart;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static string $routePath = 'licensekit/dashboard';

    protected static ?string $slug = 'licensekit/dashboard';

    protected static string|\UnitEnum|null $navigationGroup = Navigation::LICENSING;

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $title = 'Dashboard';

    protected static ?int $navigationSort = 10;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-home';

    public static function canAccess(): bool
    {
        return AdminAccess::isSupport();
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([Section::make()->schema([
            Select::make('product_id')->label('Product')->placeholder('All products')->options(fn (): array => Product::query()->orderBy('name')->pluck('name', 'id')->all()),
            Select::make('period')->label('Period')->options(['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'])->default('30')->selectablePlaceholder(false),
        ])->columns(['md' => 2])->columnSpanFull()]);
    }

    public function getWidgets(): array
    {
        return [GetStarted::class, LicenseStats::class, RedemptionsChart::class, LicensesByStatusChart::class, NeedsAttention::class, RecentActivity::class];
    }

    public function getFooter(): ?View
    {
        return view('licensekit::filament.dashboard-credits');
    }

    public function getColumns(): int|array
    {
        return ['md' => 3];
    }
}
