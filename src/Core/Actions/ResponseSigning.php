<?php

namespace LicenseKit\Core\Actions;

use LicenseKit\Core\Exceptions\LicensingException;

class ResponseSigning
{
    public function install(): void
    {
        if (! extension_loaded('sodium')) {
            throw new LicensingException('signing_unavailable', 503);
        }
        $path = (string) config('licensekit.signing_key_path');
        if (is_file($path)) {
            $this->secret();

            return;
        }
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0700, true) && ! is_dir(dirname($path))) {
            throw new LicensingException('signing_unavailable', 503);
        }
        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            throw new LicensingException('signing_unavailable', 503);
        }
        try {
            chmod($path, 0600);
            $key = base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
            if (fwrite($handle, $key) !== strlen($key) || ! fflush($handle)) {
                throw new LicensingException('signing_unavailable', 503);
            }
        } finally {
            fclose($handle);
        }
    }

    public function publicKey(): string
    {
        return base64_encode(sodium_crypto_sign_publickey_from_secretkey($this->secret()));
    }

    public function sign(string $body): string
    {
        return base64_encode(sodium_crypto_sign_detached($body, $this->secret()));
    }

    /** @return non-empty-string */
    private function secret(): string
    {
        $path = (string) config('licensekit.signing_key_path');
        if (! extension_loaded('sodium') || ! is_file($path) || ! is_readable($path)) {
            throw new LicensingException('signing_unavailable', 503);
        }
        $key = base64_decode((string) file_get_contents($path), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new LicensingException('signing_unavailable', 503);
        }

        return $key;
    }
}
