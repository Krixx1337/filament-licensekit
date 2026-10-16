<?php

namespace LicenseKit\Core\Actions;

use LicenseKit\Core\Exceptions\LicensingException;

class SerialKey
{
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function generate(): string
    {
        $value = '';
        for ($index = 0; $index < 25; $index++) {
            $value .= self::ALPHABET[random_int(0, 31)];
        }

        return implode('-', str_split($value, 5));
    }

    /** Accepts any case, optional dashes and surrounding whitespace. */
    public function normalize(#[\SensitiveParameter] string $value): string
    {
        $value = strtoupper(str_replace('-', '', trim($value)));
        if (strlen($value) !== 25 || strspn($value, self::ALPHABET) !== 25) {
            throw new LicensingException('invalid_key', 422);
        }

        return implode('-', str_split($value, 5));
    }
}
