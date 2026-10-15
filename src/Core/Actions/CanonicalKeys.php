<?php

namespace LicenseKit\Core\Actions;

use LicenseKit\Core\Contracts\KeyNormalizer;
use LicenseKit\Core\Exceptions\LicensingException;

class CanonicalKeys
{
    public function __construct(private KeyNormalizer $normalizer) {}

    public function normalize(#[\SensitiveParameter] string $value): string
    {
        if (strlen($value) > 255) {
            throw new LicensingException('invalid_key', 422);
        }
        $canonical = $this->normalizer->normalize($value);
        if ($canonical === '' || strlen($canonical) > 255 || trim($canonical) !== $canonical
            || preg_match('/\A[\x20-\x7E]+\z/D', $canonical) !== 1
            || $this->normalizer->normalize($canonical) !== $canonical) {
            throw new LicensingException('invalid_key_normalizer', 503);
        }

        return $canonical;
    }
}
