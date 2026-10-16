<?php

namespace LicenseKit\Filament\Resources\Licenses\Pages;

use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use LicenseKit\Core\Models\License;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\Concerns\DisplaysLicensingErrors;
use LicenseKit\Filament\Resources\Licenses\LicenseResource;
use LicenseKit\Filament\Support\TimeLeft;

/** @extends ViewRecord<License> */
class ViewLicense extends ViewRecord
{
    use DisplaysLicensingErrors;

    protected static string $resource = LicenseResource::class;

    public function getTitle(): string|Htmlable
    {
        $license = $this->getRecord();

        return ($license->user?->getAttribute(config('licensekit.user_label')) ?? 'Deleted customer').' · '.$license->product->name;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return TimeLeft::for($this->getRecord());
    }

    protected function getHeaderActions(): array
    {
        return [
            LicensingActions::extendLicense(),
            ActionGroup::make(LicensingActions::licenseMenu())->label('Manage')->button()->color('gray')->icon(Heroicon::OutlinedChevronDown)->iconPosition('after'),
        ];
    }
}
