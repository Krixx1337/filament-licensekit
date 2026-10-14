<?php

namespace LicenseKit\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LicenseKit\Core\Exceptions\LicensingException;

/** Stored incident safety remains active even when its optional module is disabled. */
class OutageState
{
    public function assertAccess(int $productId): void
    {
        if (Modules::enabled('outages') && ! Schema::hasTable('licensekit_outages')) {
            throw new LicensingException('licensing_unavailable', 503);
        }
        if (Schema::hasTable('licensekit_outages') && DB::table('licensekit_outages')->where('product_id', $productId)->whereNull('ended_at')->where('access_blocked', true)->when(DB::transactionLevel() > 0, fn ($query) => $query->sharedLock())->first() !== null) {
            throw new LicensingException('service_unavailable');
        }
    }

    public function assertClaim(int $productId): void
    {
        if (! Schema::hasTable('licensekit_outages')) {
            if (Modules::enabled('outages')) {
                throw new LicensingException('licensing_unavailable', 503);
            }

            return;
        }
        $outage = DB::table('licensekit_outages')->where('product_id', $productId)->whereNull('ended_at')->lockForUpdate()->first();
        if ($outage !== null && ($outage->stopped_at !== null || ! Modules::enabled('outages'))) {
            throw new LicensingException('outage_processing', 409);
        }
    }
}
