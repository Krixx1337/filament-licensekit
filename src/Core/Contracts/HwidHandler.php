<?php

namespace LicenseKit\Core\Contracts;

use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Models\Product;

interface HwidHandler
{
    /** @param array<string, mixed> $payload
     * @return array<string, mixed> */
    public function normalize(Product $product, #[\SensitiveParameter] array $payload): array;

    /** @param array<string, mixed> $bound
     * @param array<string, mixed> $presented */
    public function matches(Product $product, #[\SensitiveParameter] array $bound, #[\SensitiveParameter] array $presented): bool;

    /** @param array<string, mixed> $bound
     * @param array<string, mixed> $presented */
    public function canUpgrade(Product $product, #[\SensitiveParameter] array $bound, #[\SensitiveParameter] array $presented): bool;

    /** @param array<string, mixed>|null $bound
     * @param array<string, mixed> $presented */
    public function assertAllowed(Model $user, Product $product, #[\SensitiveParameter] ?array $bound, #[\SensitiveParameter] array $presented): void;
}
