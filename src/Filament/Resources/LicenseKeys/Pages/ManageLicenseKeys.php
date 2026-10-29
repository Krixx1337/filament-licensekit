<?php

namespace LicenseKit\Filament\Resources\LicenseKeys\Pages;

use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Concerns\DisplaysLicensingErrors;
use LicenseKit\Filament\Concerns\ShowsResult;
use LicenseKit\Filament\Enums\KeyStatus;
use LicenseKit\Filament\Resources\LicenseKeys\LicenseKeyResource;

class ManageLicenseKeys extends ManageRecords
{
    use DisplaysLicensingErrors;
    use ShowsResult;

    public function boot(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    protected static string $resource = LicenseKeyResource::class;

    protected function getHeaderActions(): array
    {
        return [LicensingActions::createKeys()];
    }

    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make('All')->badge(fn (): int => AdminAccess::scope(LicenseKey::query(), through: 'batch.plan')->count())->deferBadge()];
        foreach (KeyStatus::cases() as $status) {
            $tabs[$status->value] = Tab::make($status->getLabel())->icon($status->getIcon())
                ->badge(fn (): int => $status->scope(AdminAccess::scope(LicenseKey::query(), through: 'batch.plan'))->count())->badgeColor($status->getColor())->deferBadge()
                ->modifyQueryUsing(fn (Builder $query): Builder => $status->scope($query));
        }

        return $tabs;
    }
}
