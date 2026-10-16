<?php

namespace LicenseKit\Core\Actions;

use LicenseKit\Core\Contracts\DeviceMatcher;
use LicenseKit\Core\Models\Product;

class ExactDeviceMatcher implements DeviceMatcher
{
    public function matches(Product $product, #[\SensitiveParameter] string $bound, #[\SensitiveParameter] string $presented): bool
    {
        return hash_equals($bound, $presented);
    }
}
