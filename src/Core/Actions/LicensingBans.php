<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\LicensingBan;
use LicenseKit\Core\Models\Product;

class LicensingBans
{
    public function assertAllowed(Model $user, Product $product): void
    {
        if (config('licensekit.features.licensing_bans') === false) {
            return;
        }
        if (LicensingBan::query()->where('user_id', $user->getKey())->active()
            ->where('product_id', $product->id)->when(DB::transactionLevel() > 0, fn ($query) => $query->sharedLock())->first() !== null) {
            throw new LicensingException('license_banned');
        }
    }
}
