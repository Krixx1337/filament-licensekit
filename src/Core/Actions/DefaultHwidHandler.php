<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Contracts\HwidHandler;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Product;

class DefaultHwidHandler implements HwidHandler
{
    public function normalize(Product $product, #[\SensitiveParameter] array $payload): array
    {
        $value = $payload['value'] ?? null;
        if (! is_string($value) || trim($value) === '' || strlen($value) > 1024 || array_keys($payload) !== ['value']) {
            throw new LicensingException('invalid_hwid', 422);
        }

        return ['value' => $value];
    }

    public function matches(Product $product, #[\SensitiveParameter] array $bound, #[\SensitiveParameter] array $presented): bool
    {
        if (! is_string($bound['value'] ?? null) || trim($bound['value']) === '' || strlen($bound['value']) > 1024 || array_keys($bound) !== ['value']) {
            throw new LicensingException('licensing_unavailable', 503);
        }

        return hash_equals($bound['value'], $presented['value']);
    }

    public function canUpgrade(Product $product, #[\SensitiveParameter] array $bound, #[\SensitiveParameter] array $presented): bool
    {
        return false;
    }

    public function assertAllowed(Model $user, Product $product, #[\SensitiveParameter] ?array $bound, #[\SensitiveParameter] array $presented): void {}
}
