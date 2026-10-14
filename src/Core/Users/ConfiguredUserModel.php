<?php

namespace LicenseKit\Core\Users;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class ConfiguredUserModel
{
    public static function guard(): string
    {
        $guard = config('licensekit.user.guard') ?? config('auth.defaults.guard');
        if (! is_string($guard) || $guard === '') {
            throw new InvalidArgumentException('LicenseKit requires a configured Laravel auth guard.');
        }

        return $guard;
    }

    public static function provider(): string
    {
        $provider = config('auth.guards.'.self::guard().'.provider');
        if (! is_string($provider) || $provider === '') {
            throw new InvalidArgumentException('LicenseKit guard must have a Laravel user provider.');
        }

        return $provider;
    }

    /** @return class-string<Model&Authenticatable> */
    public static function className(): string
    {
        $model = config('auth.providers.'.self::provider().'.model');
        if (! is_string($model) || ! is_a($model, Model::class, true) || ! is_a($model, Authenticatable::class, true)) {
            throw new InvalidArgumentException('LicenseKit user model must be an Eloquent Authenticatable model.');
        }
        $instance = new $model;
        if ($instance->getConnectionName() !== null && $instance->getConnectionName() !== config('database.default')) {
            throw new InvalidArgumentException('LicenseKit users must use the default database connection.');
        }

        return $model;
    }
}
