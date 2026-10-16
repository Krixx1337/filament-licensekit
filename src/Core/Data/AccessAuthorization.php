<?php

namespace LicenseKit\Core\Data;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\Product;

final readonly class AccessAuthorization
{
    public function __construct(public Model $user, public Product $product, public ?License $license, public string $source, public ?CarbonImmutable $validUntil) {}

}
