<?php

namespace LicenseKit\Filament\Resources\Licenses\Pages;

use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use LicenseKit\Core\Models\License;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\Concerns\DisplaysLicensingErrors;
use LicenseKit\Filament\Enums\LicenseState;
use LicenseKit\Filament\Resources\Licenses\LicenseResource;

class ManageLicenses extends ManageRecords
{
    use DisplaysLicensingErrors;

    public function boot(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    protected static string $resource = LicenseResource::class;

    protected function getHeaderActions(): array
    {
        return [LicensingActions::giveLicense(), LicensingActions::suspendCustomer()];
    }

    /** Status tabs; the keys match LicenseState values so links like ?tab=expiring work. */
    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make('All')->badge(fn (): int => License::query()->count())->deferBadge()];
        $scopes = [LicenseState::Active->value => License::ACTIVE, LicenseState::ExpiringSoon->value => 'expiring', LicenseState::Paused->value => License::PAUSED,
            LicenseState::Suspended->value => License::SUSPENDED, LicenseState::Expired->value => 'expired', LicenseState::Cancelled->value => License::REVOKED];
        foreach ($scopes as $key => $scope) {
            $state = LicenseState::from($key);
            $tabs[$key] = Tab::make($state->getLabel())->icon($state->getIcon())
                ->badge(fn (): int => License::query()->effectiveStatus($scope)->count())->badgeColor($state->getColor())->deferBadge()
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where(fn (Builder $inner) => (new License)->scopeEffectiveStatus($inner, $scope)));
        }

        return $tabs;
    }
}
