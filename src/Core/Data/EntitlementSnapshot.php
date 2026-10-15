<?php

namespace LicenseKit\Core\Data;

use Carbon\CarbonImmutable;

final readonly class EntitlementSnapshot
{
    public function __construct(
        public int|string|null $userId, public int $productId, public bool $isLifetime = false,
        public bool $isRevoked = false, public bool $isBlocked = false, public ?CarbonImmutable $expiresAt = null,
        public ?CarbonImmutable $pausedAt = null, public ?int $remainingSeconds = null,
    ) {}
}
