<?php

namespace LicenseKit\Filament\Resources\Licenses\Pages;

use Filament\Resources\Pages\ViewRecord;
use LicenseKit\Core\Models\ActivityEntry;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LoaderSession;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\Resources\Licenses\LicenseResource;

/** @extends ViewRecord<License> */
class ViewLicense extends ViewRecord
{
    protected static string $resource = LicenseResource::class;

    protected string $view = 'licensekit::license-detail';

    protected function getHeaderActions(): array
    {
        return [LicensingActions::extendLicense(), LicensingActions::pauseLicense(), LicensingActions::resumeLicense(),
            LicensingActions::suspend(), LicensingActions::unsuspend(), LicensingActions::reset(), LicensingActions::banDevice(),
            LicensingActions::cancelLicense(), LicensingActions::restoreLicense(), LicensingActions::licenseNote()];
    }

    protected function getViewData(): array
    {
        $record = $this->getRecord();

        return ['license' => $record, 'keys' => LicenseKey::where('license_id', $record->id)->with('batch')->orderByDesc('id')->get(),
            'sessions' => LoaderSession::where('user_id', $record->user_id)->where('product_id', $record->product_id)->where('expires_at', '>', now())->get(),
            'entries' => ActivityEntry::where('subject_type', $record->getMorphClass())->where('subject_id', $record->id)->latest('id')->limit(100)->get()];
    }
}
