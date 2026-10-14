<?php

namespace LicenseKit\Core\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use LicenseKit\Core\Exceptions\LicensingException;
use Throwable;

/** @implements CastsAttributes<array<string, mixed>|null, array<string, mixed>|null> */
class HwidPayload implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        $mode = $this->mode();
        if ($value === null) {
            return null;
        }
        try {
            $payload = json_decode($mode === 'encrypted' ? Crypt::decryptString($value) : $value, true, 512, JSON_THROW_ON_ERROR);
            self::encode($payload);

            return $payload;
        } catch (Throwable) {
            throw new LicensingException('licensing_unavailable', 503);
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        $mode = $this->mode();
        if ($value === null) {
            return null;
        }
        try {
            $json = self::encode($value);

            return $mode === 'encrypted' ? Crypt::encryptString($json) : $json;
        } catch (Throwable) {
            throw new LicensingException('licensing_unavailable', 503);
        }
    }

    private function mode(): string
    {
        $mode = config('licensekit.device_binding.storage');
        if (! in_array($mode, ['plain', 'encrypted'], true)) {
            throw new LicensingException('licensing_unavailable', 503);
        }

        return $mode;
    }

    public static function encode(#[\SensitiveParameter] mixed $payload): string
    {
        if (! is_array($payload) || $payload === [] || count(array_filter(array_keys($payload), is_string(...))) !== count($payload)) {
            throw new \InvalidArgumentException('HWID must be a nonempty JSON object.');
        }
        self::assertJsonValues($payload);
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        if (strlen($json) > 8192) {
            throw new \InvalidArgumentException('HWID exceeds storage limit.');
        }

        return $json;
    }

    private static function assertJsonValues(#[\SensitiveParameter] mixed $value, int $depth = 0): void
    {
        if ($depth > 128 || (! is_array($value) && ! is_scalar($value) && $value !== null)) {
            throw new \InvalidArgumentException('Invalid JSON value.');
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                self::assertJsonValues($child, $depth + 1);
            }
        }
    }
}
