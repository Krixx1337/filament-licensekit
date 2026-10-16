<?php

namespace LicenseKit\Core\Contracts;

use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\Product;

interface AccessRestriction
{
    /** Read-only check. Throw LicensingException to deny; never grant or mutate access. */
    public function assertAllowed(Model $user, Product $product, ?License $license): void;
}
