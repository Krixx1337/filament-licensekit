<?php

namespace LicenseKit\Filament;

use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use LicenseKit\Core\Actions\AdminAuthorization;
use LicenseKit\Core\Users\UserAccess;
use LicenseKit\Filament\Resources\LicensingBans\LicensingBanResource;
use LicenseKit\LicenseKitPlugin;

class AdminAccess
{
    public static function allowed(?string $resource = null): bool
    {
        return self::resolveActor($resource) !== null;
    }

    private static function resolveActor(?string $resource = null, bool $fresh = false): ?Model
    {
        $panel = Filament::getCurrentPanel();
        if ($panel === null || ! $panel->hasPlugin('licensekit') || ($resource !== null && ! in_array($resource, $panel->getResources(), true))) {
            return null;
        }
        $plugin = $panel->getPlugin('licensekit');
        if (! $plugin instanceof LicenseKitPlugin
            || ($resource === LicensingBanResource::class && config('licensekit.features.licensing_bans') !== true)) {
            return null;
        }
        $user = Filament::auth()->user();
        if (! $user instanceof Model) {
            return null;
        }
        $request = app()->bound('request') ? app('request') : null;
        $request = $request instanceof Request && (! app()->runningInConsole() || app()->runningUnitTests()) ? $request : null;
        $cacheKey = 'licensekit.admin_actor.'.hash('sha256', serialize([
            $panel->getId(), spl_object_id($panel), Filament::getAuthGuard(), $user::class, $user->getConnection()->getName(), $user->getKey(),
        ]));
        if (! $fresh && $request?->attributes->has($cacheKey)) {
            $user = $request->attributes->get($cacheKey);
        } else {
            $user = $user->fresh();
            $request?->attributes->set($cacheKey, $user);
        }

        return $user instanceof Model && $user instanceof FilamentUser && $user->canAccessPanel($panel)
            && app(UserAccess::class)->available($user) ? $user : null;
    }

    /**
     * @param  class-string<Model>|Model  $subject
     * @param  list<mixed>  $context
     */
    public static function can(string $ability, string|Model $subject, ?string $resource = null, array $context = []): bool
    {
        $actor = self::resolveActor($resource);

        return $actor !== null && app(AdminAuthorization::class)->allows($actor, $ability, $subject, $context);
    }

    /**
     * @param  class-string<Model>|Model  $subject
     * @param  list<mixed>  $context
     */
    public static function authorize(string $ability, string|Model $subject, ?string $resource = null, array $context = []): Model
    {
        $actor = self::resolveActor($resource, fresh: true) ?? abort(403);
        abort_unless(app(AdminAuthorization::class)->allows($actor, $ability, $subject, $context), 403);
        auth()->shouldUse(Filament::getAuthGuard());

        return $actor;
    }

    public static function actor(): Model
    {
        $actor = self::resolveActor(fresh: true) ?? abort(403);
        auth()->shouldUse(Filament::getAuthGuard());

        return $actor;
    }

    public static function identity(): string
    {
        return 'user:'.self::actor()->getKey();
    }

    public static function plugin(): LicenseKitPlugin
    {
        $plugin = Filament::getCurrentPanel()->getPlugin('licensekit');
        if (! $plugin instanceof LicenseKitPlugin) {
            throw new \InvalidArgumentException('Panel licensekit plugin must be LicenseKitPlugin.');
        }

        return $plugin;
    }
}
