<?php

namespace LicenseKit\Filament\Widgets;

use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LoaderSession;
use LicenseKit\Filament\Enums\KeyStatus;
use LicenseKit\Filament\Enums\LicenseState;
use LicenseKit\Filament\Resources\LicenseKeys\LicenseKeyResource;
use LicenseKit\Filament\Resources\Licenses\LicenseResource;
use LicenseKit\Filament\Support\DailyCounts;

class LicenseStats extends StatsOverviewWidget
{
    use DashboardFilters;
    use InteractsWithPageFilters;

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $days = $this->periodDays();
        $redeemed = $this->keys()->where('redeemed_at', '>=', now()->subDays($days))->count();
        $before = $this->keys()->whereBetween('redeemed_at', [now()->subDays($days * 2), now()->subDays($days)])->count();
        $unused = KeyStatus::Unused->scope($this->keys())->count();
        $expiring = $this->licenses()->effectiveStatus('expiring')->count();
        $online = LoaderSession::query()->where('expires_at', '>', now())
            ->when($this->productId(), fn ($query, int $id) => $query->where('product_id', $id))->distinct()->count('user_id');

        return [
            Stat::make('Active licenses', number_format($this->licenses()->effectiveStatus(License::ACTIVE)->count()))
                ->description(number_format($online).' online in your app now')->descriptionIcon(Heroicon::OutlinedSignal)
                ->chart($this->sparkline(DailyCounts::last(14, $this->licenses(), 'created_at')))->color('success'),
            Stat::make('Keys redeemed', number_format($redeemed))
                ->description($this->change($redeemed, $before, $days))
                ->descriptionIcon($redeemed >= $before ? Heroicon::ArrowTrendingUp : Heroicon::ArrowTrendingDown)
                ->chart($this->sparkline(DailyCounts::last(min($days, 30), $this->keys(), 'redeemed_at')))->color($redeemed >= $before ? 'success' : 'warning'),
            Stat::make('Unused keys', number_format($unused))
                ->description($unused === 0 ? 'Generate keys to keep selling' : 'Ready to sell')->descriptionIcon(Heroicon::OutlinedKey)
                ->color($unused === 0 ? 'danger' : 'info')->url(LicenseKeyResource::getUrl('index', ['tab' => KeyStatus::Unused->value])),
            Stat::make('Expiring this week', number_format($expiring))
                ->description($expiring === 0 ? 'Nobody runs out in the next 7 days' : 'Remind them to renew')->descriptionIcon(Heroicon::OutlinedClock)
                ->color($expiring === 0 ? 'gray' : 'warning')->url(LicenseResource::getUrl('index', ['tab' => LicenseState::ExpiringSoon->value])),
        ];
    }

    /**
     * @param  array<string, int>  $series
     * @return list<float>
     */
    private function sparkline(array $series): array
    {
        return array_map(fn (int $count): float => $count, array_values($series));
    }

    /** @return array<string, int> */
    protected function getColumns(): array
    {
        return ['md' => 2, 'xl' => 4];
    }

    private function change(int $now, int $before, int $days): string
    {
        if ($before === 0) {
            return $now === 0 ? 'None in the previous '.$days.' days either' : 'Up from 0 in the previous '.$days.' days';
        }
        $percent = (int) round(($now - $before) / $before * 100);

        return ($percent >= 0 ? '+' : '').$percent.'% vs previous '.$days.' days';
    }
}
