<?php

namespace LicenseKit\Core\Contracts;

use LicenseKit\Core\Models\Product;

/**
 * Decides whether the device a customer presents is the one their license is locked to.
 * Replace the default exact match to tolerate hardware changes (for example a new GPU or disk).
 */
interface DeviceMatcher
{
    public function matches(Product $product, #[\SensitiveParameter] string $bound, #[\SensitiveParameter] string $presented): bool;
}
