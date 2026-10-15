<?php

namespace LicenseKit\Core\Actions;

use LicenseKit\Core\Contracts\KeyNormalizer;
use LicenseKit\Core\Exceptions\LicensingException;

class StandardKeyNormalizer implements KeyNormalizer
{
    public function normalize(#[\SensitiveParameter] string $value): string
    {
        $value = strtoupper(str_replace('-', '', trim($value)));
        if (strlen($value) !== 25 || strspn($value, SerialKey::ALPHABET) !== 25) {
            throw new LicensingException('invalid_key', 422);
        }

        return implode('-', str_split($value, 5));
    }
}
