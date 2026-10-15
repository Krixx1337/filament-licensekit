<?php

namespace LicenseKit\Core\Data;

use Carbon\CarbonImmutable;

final readonly class ResetSnapshot
{
    /** @param array<string, mixed>|null $beforeHwid */
    public function __construct(
        public int|string $userId, public ?int $productId, public CarbonImmutable $resetAt,
        public string $performedBy, public string $actorId, public ?string $reason = null,
        #[\SensitiveParameter] public ?array $beforeHwid = null,
    ) {}
}
