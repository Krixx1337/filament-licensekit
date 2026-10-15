<?php

namespace LicenseKit\Core\Data;

use Carbon\CarbonImmutable;

final readonly class KeySnapshot
{
    public function __construct(
        #[\SensitiveParameter] public string $key, public int $planId, public ?int $durationDays,
        public ?int $entitlementId = null, public ?CarbonImmutable $claimedAt = null,
        public ?CarbonImmutable $revokedAt = null,
    ) {}
}
