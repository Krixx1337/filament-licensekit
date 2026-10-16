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

    protected ?string $maxHeight = '300px';

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
        $slices = [];
        foreach (self::SLICES as $scope => [$state, $colour]) {
            $query = $this->licenses()->effectiveStatus($scope === 'active' ? License::ACTIVE : $scope);
            if ($scope === 'active') {
                $query->whereNot(fn ($q) => $q->effectiveStatus('expiring'));
            }
            // Empty slices are left out so the legend only lists statuses that exist.
            if ($count = $query->count()) {
                $slices[] = [$state->getLabel(), $count, $colour];
            }
        }

        return [
            'datasets' => [['data' => array_column($slices, 1), 'backgroundColor' => array_column($slices, 2), 'borderWidth' => 0]],
            'labels' => array_column($slices, 0),
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
