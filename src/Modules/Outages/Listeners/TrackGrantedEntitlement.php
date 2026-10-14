<?php

namespace LicenseKit\Modules\Outages\Listeners;

use LicenseKit\Core\Events\EntitlementGranted;
use LicenseKit\Modules\Outages\Actions\ManageOutages;

class TrackGrantedEntitlement
{
    public function __construct(private ManageOutages $outages) {}

    public function handle(EntitlementGranted $event): void
    {
        $this->outages->trackGrant($event);
    }
}
