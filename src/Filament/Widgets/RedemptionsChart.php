<?php

namespace LicenseKit\Filament\Widgets;

use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use LicenseKit\Filament\Support\DailyCounts;

class RedemptionsChart extends ChartWidget
{
    use DashboardFilters;
    use InteractsWithPageFilters;

    protected static ?int $sort = 2;

    protected ?string $heading = 'Keys redeemed per day';

    protected ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = ['md' => 2];

    protected ?string $pollingInterval = null;

    protected function getData(): array
    {
        $series = DailyCounts::last($this->periodDays(), $this->keys(), 'redeemed_at');

        return [
            'datasets' => [['label' => 'Keys redeemed', 'data' => array_values($series), 'fill' => 'start', 'tension' => 0.3]],
            'labels' => array_map(fn (string $day): string => CarbonImmutable::parse($day)->format('j M'), array_keys($series)),
        ];
    }

    protected function getOptions(): array
    {
        return ['plugins' => ['legend' => ['display' => false]],
            'scales' => ['x' => ['ticks' => ['maxTicksLimit' => 8, 'maxRotation' => 0]], 'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]]];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
