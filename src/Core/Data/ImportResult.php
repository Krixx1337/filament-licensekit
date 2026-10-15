<?php

namespace LicenseKit\Core\Data;

final readonly class ImportResult
{
    public function __construct(public string $recordType, public ?int $targetId, public bool $replayed, public bool $dryRun) {}
}
