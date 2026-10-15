<?php

namespace LicenseKit\Core\Data;

final readonly class ImportSource
{
    public function __construct(public string $namespace, public string $reference, public string $actor) {}
}
