<?php

namespace LicenseKit\Core\Contracts;

interface KeyNormalizer
{
    /** Return one deterministic, idempotent canonical key. Never query storage here. */
    public function normalize(#[\SensitiveParameter] string $value): string;
}
