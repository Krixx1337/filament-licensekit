<?php

namespace LicenseKit\Core\Data;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\Product;

final readonly class AccessAuthorization
{
    public function __construct(public Model $user, public Product $product, public ?License $license, public string $source, public ?CarbonImmutable $validUntil) {}

    /** @return array{source: string, valid_until: string|null} */
    public function summary(): array
    {
        return ['source' => $this->source, 'valid_until' => $this->validUntil?->toIso8601String()];
    }
}
