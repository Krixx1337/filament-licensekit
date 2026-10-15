<?php

namespace LicenseKit\Core\Data;

use Carbon\CarbonImmutable;

final readonly class DeviceSnapshot
{
    /** @param array<string, mixed>|null $hwid */
    public function __construct(
        public int|string $userId, public int $productId, #[\SensitiveParameter] public ?array $hwid,
        public ?CarbonImmutable $boundAt = null, public ?CarbonImmutable $lastResetAt = null,
    ) {}
}
