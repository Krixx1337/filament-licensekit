<?php

namespace LicenseKit\Core\Actions;

use Illuminate\Support\Facades\Validator;

class LicenseDuration
{
    public const MAXIMUM_DAYS = 3650;

    public static function days(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        Validator::make(['duration_days' => $value], ['duration_days' => ['required', 'integer', 'min:1', 'max:'.self::MAXIMUM_DAYS]])->validate();

        return (int) $value;
    }

    public static function seconds(mixed $days): ?int
    {
        $days = self::days($days);

        return $days === null ? null : $days * 86400;
    }
}
