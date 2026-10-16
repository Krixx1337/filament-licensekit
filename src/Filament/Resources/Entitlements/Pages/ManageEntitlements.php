<?php

namespace LicenseKit\Filament\Resources\Entitlements\Pages;

use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Collection;
use LicenseKit\Core\Actions\DeviceBindings;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\Concerns\DisplaysLicensingErrors;
use LicenseKit\Filament\Resources\Entitlements\EntitlementResource;

class ManageEntitlements extends ManageRecords
{
    use DisplaysLicensingErrors;

    public function boot(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    protected static string $resource = EntitlementResource::class;

    /** @return Collection<int, Entitlement>|Paginator<int, Entitlement>|CursorPaginator<int, Entitlement> */
    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        $records = parent::getTableRecords();
        $collection = $records instanceof Collection ? $records : collect($records->items());
        $unloaded = $collection->filter(fn (Entitlement $entitlement): bool => ! $entitlement->relationLoaded('binding'));
        if ($unloaded->isNotEmpty()) {
            app(DeviceBindings::class)->preload($unloaded);
        }

        return $records;
    }

    protected function getHeaderActions(): array
    {
        return [LicensingActions::suspendCustomer()];
    }
}
