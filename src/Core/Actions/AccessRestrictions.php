<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LicenseKit\Core\Contracts\AccessRestriction;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\Product;

class AccessRestrictions
{
    public function __construct(private Container $container) {}

    public function assertAllowed(Model $user, Product $product, ?Entitlement $entitlement): void
    {
        foreach (config('licensekit.access_restrictions', []) as $class) {
            if (! is_string($class) || ! is_a($class, AccessRestriction::class, true)) {
                throw new InvalidArgumentException('LicenseKit access restrictions must implement AccessRestriction.');
            }
            $this->container->make($class)->assertAllowed($user, $product, $entitlement);
        }
    }
}
