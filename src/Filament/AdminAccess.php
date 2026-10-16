<?php

namespace LicenseKit\Filament;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Two roles for the admin panel:
 * - licensekit.admin: everything.
 * - licensekit.support: look up customers and fix their licenses (suspend, pause, add time, reset device).
 *
 * Hosts redefine either gate in their own service provider; by default every panel user is an admin.
 */
class AdminAccess
{
    public const ADMIN = 'licensekit.admin';

    public const SUPPORT = 'licensekit.support';

    public static function isAdmin(): bool
    {
        $user = self::user();

        return $user !== null && Gate::forUser($user)->allows(self::ADMIN);
    }

    public static function isSupport(): bool
    {
        $user = self::user();

        return $user !== null && (Gate::forUser($user)->allows(self::SUPPORT) || Gate::forUser($user)->allows(self::ADMIN));
    }

    public static function actor(): Model
    {
        $user = self::user() ?? abort(403);
        auth()->shouldUse(Filament::getAuthGuard());

        return $user;
    }

    private static function user(): ?Model
    {
        $panel = Filament::getCurrentPanel();
        $user = Filament::auth()->user();

        return $panel !== null && $panel->hasPlugin('licensekit') && $user instanceof Model ? $user : null;
    }
}
