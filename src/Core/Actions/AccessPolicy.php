<?php

namespace LicenseKit\Core\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LicenseKit\Core\Contracts\ReadinessCheck;
use LicenseKit\Core\Data\AccessAuthorization;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\Product;
use LicenseKit\Core\Users\UserAccess;

class AccessPolicy
{
    public function __construct(private UserAccess $users, private AccessRestrictions $restrictions, private LicenseClock $clock, private ReadinessCheck $readiness) {}

    public function authorize(Model $user, Product $product, ?CarbonImmutable $freeUntil = null): AccessAuthorization
    {
        $user = $user->newQuery()->whereKey($user->getKey())->when(DB::transactionLevel() > 0, fn ($query) => $query->sharedLock())->first() ?? throw new LicensingException('user_unavailable');
        $product = Product::whereKey($product->id)->when(DB::transactionLevel() > 0, fn ($query) => $query->sharedLock())->first() ?? throw new LicensingException('service_unavailable');
        $license = $this->check($user, $product);
        if ($license !== null && ! $license->isRevoked() && $this->clock->active($license)) {
            return new AccessAuthorization($user, $product, $license, 'paid', $license->expires_at);
        }
        if ($freeUntil !== null && $freeUntil->isFuture()) {
            return new AccessAuthorization($user, $product, null, 'host_free', $freeUntil->utc());
        }
        throw new LicensingException(match (true) {
            $license === null, $license->isRevoked(), ! $license->is_lifetime && $license->expires_at === null => 'license_unavailable',
            default => 'license_expired',
        });
    }

    /** Checks universal restrictions only; returning an license does not authorize its use. */
    public function assertAllowed(Model $user, Product $product): ?License
    {
        $user = $user->newQuery()->whereKey($user->getKey())->when(DB::transactionLevel() > 0, fn ($query) => $query->sharedLock())->first() ?? throw new LicensingException('user_unavailable');
        $product = Product::whereKey($product->id)->when(DB::transactionLevel() > 0, fn ($query) => $query->sharedLock())->first() ?? throw new LicensingException('service_unavailable');

        return $this->check($user, $product);
    }

    private function check(Model $user, Product $product): ?License
    {
        $this->readiness->assertReady();
        if (! $this->users->available($user)) {
            throw new LicensingException('user_unavailable');
        }
        if ($product->status === Product::MAINTENANCE) {
            throw new LicensingException('service_unavailable');
        }
        $license = License::where('user_id', $user->getKey())->where('product_id', $product->id)->when(DB::transactionLevel() > 0, fn ($query) => $query->sharedLock())->first();
        $this->restrictions->assertAllowed($user, $product, $license);
        if ($license?->isSuspended()) {
            throw new LicensingException('license_suspended');
        }
        if ($license?->paused_at !== null) {
            throw new LicensingException('subscription_paused');
        }

        return $license;
    }
}
