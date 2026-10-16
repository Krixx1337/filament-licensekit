<?php

namespace LicenseKit\Core\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LicenseKit\Core\Contracts\ReadinessCheck;
use LicenseKit\Core\Data\AccessAuthorization;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\Product;
use LicenseKit\Core\Users\UserAccess;

class AccessPolicy
{
    public function __construct(private UserAccess $users, private AccessRestrictions $restrictions, private EntitlementClock $clock, private ReadinessCheck $readiness) {}

    public function authorize(Model $user, Product $product, ?CarbonImmutable $freeUntil = null): AccessAuthorization
    {
        $user = $user->newQuery()->whereKey($user->getKey())->when(DB::transactionLevel() > 0, fn ($query) => $query->sharedLock())->first() ?? throw new LicensingException('user_unavailable');
        $product = Product::whereKey($product->id)->when(DB::transactionLevel() > 0, fn ($query) => $query->sharedLock())->first() ?? throw new LicensingException('service_unavailable');
        $entitlement = $this->check($user, $product);
        if ($entitlement !== null && ! $entitlement->isRevoked() && $this->clock->active($entitlement)) {
            return new AccessAuthorization($user, $product, $entitlement, 'paid', $entitlement->expires_at);
        }
        if ($freeUntil !== null && $freeUntil->isFuture()) {
            return new AccessAuthorization($user, $product, null, 'host_free', $freeUntil->utc());
        }
        throw new LicensingException(match (true) {
            $entitlement === null, $entitlement->isRevoked() => 'license_unavailable',
            default => 'license_expired',
        });
    }

    /** Checks universal restrictions only; returning an entitlement does not authorize its use. */
    public function assertAllowed(Model $user, Product $product): ?Entitlement
    {
        $user = $user->newQuery()->whereKey($user->getKey())->when(DB::transactionLevel() > 0, fn ($query) => $query->sharedLock())->first() ?? throw new LicensingException('user_unavailable');
        $product = Product::whereKey($product->id)->when(DB::transactionLevel() > 0, fn ($query) => $query->sharedLock())->first() ?? throw new LicensingException('service_unavailable');

        return $this->check($user, $product);
    }

    private function check(Model $user, Product $product): ?Entitlement
    {
        $this->readiness->assertReady();
        if (! $this->users->available($user)) {
            throw new LicensingException('user_unavailable');
        }
        if ($product->status === Product::MAINTENANCE) {
            throw new LicensingException('service_unavailable');
        }
        $entitlement = Entitlement::where('user_id', $user->getKey())->where('product_id', $product->id)->when(DB::transactionLevel() > 0, fn ($query) => $query->sharedLock())->first();
        $this->restrictions->assertAllowed($user, $product, $entitlement);
        if ($entitlement?->isSuspended()) {
            throw new LicensingException('license_suspended');
        }
        if ($entitlement?->paused_at !== null) {
            throw new LicensingException('subscription_paused');
        }

        return $entitlement;
    }
}
