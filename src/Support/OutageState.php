<?php

namespace LicenseKit\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LicenseKit\Core\Exceptions\LicensingException;

/** Stored incident safety remains active even when its optional module is disabled. */
class OutageState
{
    /** Caller holds the product lock, including when the optional module is disabled. */
    public function assertClockChange(int $productId): void
    {
        foreach (['licensekit_outages', 'licensekit_compensation_operations'] as $table) {
            if (! Schema::hasTable($table)) {
                if (Modules::enabled('outages')) {
                    throw new LicensingException('licensing_unavailable', 503);
                }

                continue;
            }
            $query = DB::table($table)->where('product_id', $productId);
            $query = $table === 'licensekit_outages' ? $query->whereNull('ended_at') : $query->whereIn('status', ['pending', 'processing', 'failed']);
            if ($query->lockForUpdate()->first() !== null) {
                throw new LicensingException('operation_conflict', 409);
            }
        }
    }

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
