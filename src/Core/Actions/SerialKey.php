<?php

namespace LicenseKit\Core\Actions;

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

    /** @deprecated Resolve KeyNormalizer for customer input. */
    public function normalize(#[\SensitiveParameter] string $value): string
    {
        return app(CanonicalKeys::class)->normalize($value);
    }
}
