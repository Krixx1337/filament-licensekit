<?php

namespace LicenseKit\Filament\Widgets;

use Illuminate\Database\Eloquent\Builder;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Filament\AdminAccess;

/** Reads the dashboard's product and period filters. */
trait DashboardFilters
{
    protected function periodDays(): int
    {
        $days = (int) ($this->pageFilters['period'] ?? 30);

        return in_array($days, [7, 30, 90], true) ? $days : 30;
    }

    protected function productId(): ?int
    {
        $id = $this->pageFilters['product_id'] ?? null;

        return filled($id) ? (int) $id : null;
    }

    /** @return Builder<License> */
    protected function licenses(): Builder
    {
        return AdminAccess::scope(License::query())->when($this->productId(), fn (Builder $query, int $id) => $query->where('product_id', $id));
    }

    /** @return Builder<LicenseKey> */
    protected function keys(): Builder
    {
        return AdminAccess::scope(LicenseKey::query(), through: 'batch.plan')
            ->when($this->productId(), fn (Builder $query, int $id) => $query->whereHas('batch.plan', fn (Builder $plan) => $plan->where('product_id', $id)));
    }
}
