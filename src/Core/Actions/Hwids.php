<?php

namespace LicenseKit\Core\Actions;

use LicenseKit\Core\Casts\HwidPayload;
use LicenseKit\Core\Contracts\HwidHandler;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Product;
use Throwable;

class Hwids
{
    public function __construct(public readonly HwidHandler $handler) {}

    /** @param array<string, mixed> $payload
     * @return array<string, mixed> */
    public function normalize(Product $product, #[\SensitiveParameter] array $payload): array
    {
        $this->validate($payload, 'invalid_hwid', 422);
        $normalized = $this->handler->normalize($product, $payload);
        $this->validate($normalized, 'licensing_unavailable', 503);

        return $normalized;
    }

    /** @param array<string, mixed> $bound
     * @param array<string, mixed> $presented */
    public function verify(Product $product, #[\SensitiveParameter] array $bound, #[\SensitiveParameter] array $presented): void
    {
        if (! $this->handler->matches($product, $bound, $presented)) {
            throw new LicensingException('hwid_mismatch');
        }
    }

    /** @param array<string, mixed> $payload */
    private function validate(#[\SensitiveParameter] array $payload, string $error, int $status): void
    {
        try {
            HwidPayload::encode($payload);
        } catch (Throwable) {
            throw new LicensingException($error, $status);
        }
    }
}
