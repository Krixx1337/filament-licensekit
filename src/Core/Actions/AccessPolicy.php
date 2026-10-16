<?php

namespace LicenseKit\Core\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Data\AccessAuthorization;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\Product;
use LicenseKit\Core\Users\UserAccess;

class AccessPolicy
{
    public function __construct(private UserAccess $users, private LicenseClock $clock) {}

    public function authorize(Model $user, Product $product): AccessAuthorization
    {
        $user = $user->newQuery()->whereKey($user->getKey())->first() ?? throw new LicensingException('user_unavailable');
        $product = Product::whereKey($product->id)->first() ?? throw new LicensingException('maintenance');
        $license = $this->check($user, $product);
        if ($license?->paused_at !== null && $license->paused_until?->lessThanOrEqualTo(now())) {
            $license = clone $license;
            $license->paused_at = null;
            $license->status = License::ACTIVE;
            $license->expires_at = CarbonImmutable::now()->startOfSecond()->addSeconds($license->remaining_seconds ?? 0);
        }
        if ($license !== null && ! $license->isRevoked() && $this->clock->active($license)) {
            return new AccessAuthorization($user, $product, $license, 'paid', $license->expires_at);
        }
        if ($product->freeAccessActive()) {
            return new AccessAuthorization($user, $product, $license, 'free', $product->free_until);
        }
        if ($license?->paused_at !== null) {
            throw new LicensingException('paused');
        }
        throw new LicensingException(match (true) {
            $license === null, $license->isRevoked(), ! $license->is_lifetime && $license->expires_at === null => 'no_license',
            default => 'expired',
        });
    }

    /** Checks universal restrictions only; returning a license does not authorize its use. */
    public function assertAllowed(Model $user, Product $product): ?License
    {
        $user = $user->newQuery()->whereKey($user->getKey())->first() ?? throw new LicensingException('user_unavailable');
        $product = Product::whereKey($product->id)->first() ?? throw new LicensingException('maintenance');

        return $this->check($user, $product);
    }

    private function check(Model $user, Product $product): ?License
    {
        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            throw new LicensingException('email_not_verified');
        }
        if (! $this->users->available($user)) {
            throw new LicensingException('user_unavailable');
        }
        if ($product->status === Product::MAINTENANCE) {
            throw new LicensingException('maintenance');
        }
        $license = License::where('user_id', $user->getKey())->where('product_id', $product->id)->first();
        if ($license?->isSuspended()) {
            throw new LicensingException('suspended');
        }
        if ($license?->isRevoked()) {
            throw new LicensingException('cancelled');
        }

        return $license;
    }
}
