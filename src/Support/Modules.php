<?php

namespace LicenseKit\Support;

use InvalidArgumentException;
use LicenseKit\Core\Exceptions\LicensingException;

final class Modules
{
    public static function enabled(string $module): bool
    {
        if (! in_array($module, ['outages'], true)) {
            throw new InvalidArgumentException('Unknown LicenseKit module: '.$module);
        }
        $enabled = config('licensekit.modules.'.$module);
        if (! is_bool($enabled)) {
            throw new InvalidArgumentException('LicenseKit module flags must be booleans.');
        }

        return $enabled;
    }

    public static function requireEnabled(string $module): void
    {
        if (! self::enabled($module)) {
            throw new LicensingException('module_disabled', 503);
        }
    }
}
