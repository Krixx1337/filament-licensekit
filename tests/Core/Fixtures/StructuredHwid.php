<?php

namespace LicenseKit\Tests\Core\Fixtures;

use Illuminate\Database\Eloquent\Model;
use LicenseKit\Core\Actions\DefaultHwidHandler;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Product;

class StructuredHwid extends DefaultHwidHandler
{
    public function normalize(Product $product, array $payload): array
    {
        if (! is_int($payload['v'] ?? null) || ! in_array($payload['v'], [1, 2, 3], true) || ! is_string($payload['id'] ?? null) || trim($payload['id']) === '') {
            throw new LicensingException('invalid_hwid', 422);
        }
        ksort($payload);
        $payload['id'] = strtolower($payload['id']);

        return $payload;
    }

    public function matches(Product $product, array $bound, array $presented): bool
    {
        return $product->slug === 'strict' ? $bound === $presented : $bound['id'] === $presented['id'];
    }

    public function canUpgrade(Product $product, array $bound, array $presented): bool
    {
        return $presented['v'] > $bound['v'];
    }

    public function assertAllowed(Model $user, Product $product, ?array $bound, array $presented): void
    {
        if (config('testing.device_denied')) {
            throw new LicensingException('device_banned', 403);
        }
    }
}
