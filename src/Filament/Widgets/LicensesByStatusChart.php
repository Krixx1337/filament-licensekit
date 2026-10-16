<?php

namespace LicenseKit\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use LicenseKit\Core\Models\License;
use LicenseKit\Filament\Enums\LicenseState;

class LicensesByStatusChart extends ChartWidget
{
    use DashboardFilters;
    use InteractsWithPageFilters;

    protected static ?int $sort = 3;

    protected ?string $heading = 'Licenses by status';

    protected ?string $maxHeight = '260px';

    protected ?string $pollingInterval = null;

    /** Chart.js needs literal colours; these follow the status badge colours. */
    private const SLICES = [
        'active' => [LicenseState::Active, '#10b981'],
        'expiring' => [LicenseState::ExpiringSoon, '#f59e0b'],
        License::PAUSED => [LicenseState::Paused, '#60a5fa'],
        License::SUSPENDED => [LicenseState::Suspended, '#ef4444'],
        'expired' => [LicenseState::Expired, '#9ca3af'],
        License::REVOKED => [LicenseState::Cancelled, '#6b7280'],
    ];

    protected function getData(): array
    {
        $counts = [];
        foreach (array_keys(self::SLICES) as $scope) {
            $query = $this->licenses()->effectiveStatus($scope === 'active' ? License::ACTIVE : $scope);
            if ($scope === 'active') {
                $query->whereNot(fn ($q) => $q->effectiveStatus('expiring'));
            }
            $counts[] = $query->count();
        }

        return [
            'datasets' => [['data' => $counts, 'backgroundColor' => array_column(self::SLICES, 1), 'borderWidth' => 0]],
            'labels' => array_map(fn (LicenseState $state): string => $state->getLabel(), array_column(self::SLICES, 0)),
        ];
    }

    protected function getOptions(): array
    {
        return ['plugins' => ['legend' => ['position' => 'bottom']], 'scales' => ['x' => ['display' => false], 'y' => ['display' => false]], 'cutout' => '65%'];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
