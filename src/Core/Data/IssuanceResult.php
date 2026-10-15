<?php

namespace LicenseKit\Core\Data;

final readonly class IssuanceResult
{
    /** @param list<int> $keyIds
     * @param list<string>|null $serials */
    public function __construct(public int $batchId, public array $keyIds, public bool $replayed, #[\SensitiveParameter] public ?array $serials = null) {}
}
