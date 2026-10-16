<?php

namespace LicenseKit\Core;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LicenseKit\Core\Contracts\DeviceMatcher;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Http\LicensingExceptionResponse;
use LicenseKit\Core\Http\Middleware\JsonResponse;
use LicenseKit\Core\Observers\UserObserver;
use LicenseKit\Core\Users\ConfiguredUserModel;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DeviceMatcher::class, function ($app): DeviceMatcher {
            $handler = config('licensekit.device_matcher');
            if (! is_string($handler) || ! is_a($handler, DeviceMatcher::class, true)) {
                throw new LicensingException('licensing_unavailable', 503);
            }

            return $app->make($handler);
        });
    }

    public function boot(): void
    {
        // Authentication middleware can change Laravel's default guard during a request.
        if (config('licensekit.user.guard') === null) {
            config(['licensekit.user.guard' => config('auth.defaults.guard')]);
        }
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            $adapter = app(LicensingExceptionResponse::class);
            $handler->renderable([$adapter, 'render']);
            $handler->renderable([$adapter, 'renderStorageFailure']);
            $handler->reportable([$adapter, 'reportStorageFailure']);
        });
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations/core');
        try {
            ConfiguredUserModel::className()::observe(UserObserver::class);
        } catch (\InvalidArgumentException) {
            // Setup commands report this error; API and panel guards refuse access.
        }
        $this->app->make(Router::class)->aliasMiddleware('licensekit.json', JsonResponse::class);
        RateLimiter::for('licensekit.management', function (Request $request): Limit {
            $read = $request->isMethod('GET') || $request->isMethod('HEAD');

            return Limit::perMinute((int) config('licensekit.management.rate_limits.'.($read ? 'reads' : 'writes')))
                ->by('licensekit-api:'.$request->attributes->get('licensekit.management_client_id').':'.($read ? 'read' : 'write'));
        });
        if (config('licensekit.management.enabled') === true) {
            $this->loadRoutesFrom(__DIR__.'/../../routes/management.php');
        }
    }
}
