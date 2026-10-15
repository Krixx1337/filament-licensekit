<?php

namespace LicenseKit\Core\Data;

use Carbon\CarbonImmutable;

final readonly class ClaimProvenance
{
    /** @param array<string, mixed> $before
     * @param array<string, mixed> $after */
    public function __construct(
        public int $keyId, public int $batchId, public string $issuer, public string $reference,
        public string $operation, public int $entitlementId, public int|string $userId, public int $productId,
        public CarbonImmutable $claimedAt, public array $before, public array $after,
    ) {}
}
