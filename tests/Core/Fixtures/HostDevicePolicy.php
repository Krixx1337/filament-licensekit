<?php

namespace LicenseKit\Tests\Core\Fixtures;

use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Actions\DefaultHwidHandler;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Product;
use RuntimeException;

class HostDevicePolicy extends DefaultHwidHandler
{
    public function assertAllowed(Model $user, Product $product, ?array $bound, array $presented): void
    {
        if (config('testing.policy_failure')) {
            throw new RuntimeException('Host policy unavailable');
        }
        $blockedProduct = config('testing.blocked_product');
        if (($blockedProduct === null || $blockedProduct === $product->id)
            && in_array(config('testing.blocked_device'), [$bound['value'] ?? null, $presented['value']], true)) {
            throw new LicensingException('device_banned', 403);
        }
    }
}
