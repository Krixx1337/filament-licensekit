<?php

namespace LicenseKit\Core;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LicenseKit\Core\Contracts\DeviceMatcher;
use LicenseKit\Core\Events\AccessRevoked;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Http\LicensingExceptionResponse;
use LicenseKit\Core\Http\Middleware\JsonResponse;
use LicenseKit\Core\Models\LoaderSession;
use LicenseKit\Core\Observers\UserObserver;
use LicenseKit\Core\Users\ConfiguredUserModel;
use LicenseKit\Livewire\MyLicenses;
use Livewire\Livewire;

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
        if (config('licensekit.user_guard') === null) {
            config(['licensekit.user_guard' => config('auth.defaults.guard')]);
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

            return Limit::perMinute(($read ? 120 : 60))
                ->by('licensekit-api:'.$request->attributes->get('licensekit.management_client_id').':'.($read ? 'read' : 'write'));
        });
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'licensekit');
        $this->publishes([__DIR__.'/../../resources/views' => resource_path('views/vendor/licensekit')], 'licensekit-views');
        Livewire::component('licensekit.my-licenses', MyLicenses::class);
        if (config('licensekit.portal_api_enabled') === true && is_string(config('licensekit.portal_api_prefix'))) {
            $this->loadRoutesFrom(__DIR__.'/../../routes/portal.php');
        }
        $this->loadTranslationsFrom(__DIR__.'/../../resources/lang', 'licensekit');
        RateLimiter::for('licensekit.login', fn (Request $request): array => [
            Limit::perMinute(30)->by('lk-ip:'.$request->ip()),
            Limit::perMinute(5)->by('lk-login:'.hash('sha256', mb_strtolower(is_string($request->input('login')) ? $request->input('login') : '').'|'.$request->ip())),
        ]);
        RateLimiter::for('licensekit.session', fn (Request $request): Limit => Limit::perMinute(120)
            ->by('lk-session:'.hash('sha256', (is_string($request->input('token')) ? $request->input('token') : '')).'|'.$request->ip()));
        if (config('licensekit.client_enabled') === true && is_string(config('licensekit.client_prefix'))) {
            $this->loadRoutesFrom(__DIR__.'/../../routes/client.php');
        }
        Event::listen(AccessRevoked::class,
            fn ($event) => LoaderSession::where('user_id', $event->license->user_id)->where('product_id', $event->license->product_id)->delete());
        if (config('licensekit.store_api_enabled') === true && is_string(config('licensekit.store_api_prefix'))) {
            $this->loadRoutesFrom(__DIR__.'/../../routes/management.php');
        }
    }
}
