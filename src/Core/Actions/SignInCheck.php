<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Contracts\DeviceMatcher;
use LicenseKit\Core\Data\AccessAuthorization;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\Product;

/** What the app would tell a customer right now, without changing or logging anything. */
class SignInCheck
{
    public function __construct(private AccessPolicy $access, private DeviceBans $bans, private DeviceMatcher $matcher) {}

    /** Throws LicensingException with the reason when the app would refuse. */
    public function check(Model $user, Product $product, #[\SensitiveParameter] ?string $device = null): AccessAuthorization
    {
        $access = $this->access->authorize($user, $product);
        if ($device !== null && $device !== '') {
            $this->bans->assertAllowed($product, $device);
            $bound = $access->license?->device;
            if ($product->device_lock && $bound !== null && ! $this->matcher->matches($product, $bound, $device)) {
                throw new LicensingException('device_mismatch');
            }
        }

        return $access;
    }

    /** Checks a license as its customer's locked PC would see it. */
    public function forLicense(License $license): AccessAuthorization
    {
        $user = $license->user ?? throw new LicensingException('user_unavailable');

        return $this->check($user, $license->product, $license->device);
    }
}
