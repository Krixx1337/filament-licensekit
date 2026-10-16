<?php

namespace LicenseKit\Tests\Core\Fixtures;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use LicenseKit\Core\Events\LicenseAccessInvalidated;
use LicenseKit\Core\Events\ProductAccessInvalidated;
use LicenseKit\Core\Users\ConfiguredUserModel;

class ClientProtocolProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/client-config.php', 'host_client');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/client-migrations');
        ConfiguredUserModel::className()::observe(ClientUserObserver::class);
        Event::listen(HostUserSecurityChanged::class, fn (HostUserSecurityChanged $event) => DB::transaction(fn () => app(SessionCleanup::class)->forUser($event->user)));
        Event::listen(ProductAccessInvalidated::class, [SessionCleanup::class, 'productInvalidated']);
        Event::listen(LicenseAccessInvalidated::class, [SessionCleanup::class, 'accessInvalidated']);
        if (config('host_client.enabled', true) && ! $this->app->routesAreCached()) {
            Route::middleware(['api', 'licensekit.json'])->prefix('api/licensekit/v1')->group(function (): void {
                Route::post('products/{product:slug}/login', [ClientSessionController::class, 'login'])->name('licensekit.desktop.login')->middleware('throttle:client-login');
                Route::post('products/{product:slug}/heartbeat', [ClientSessionController::class, 'heartbeat'])->name('licensekit.desktop.heartbeat')->middleware('throttle:client-heartbeat');
            });
        }
        RateLimiter::for('client-login', function (Request $request): array {
            $identity = $request->input('login');

            return [Limit::perMinute(60)->by('client-ip:'.$request->ip()), Limit::perMinute(5)->by(mb_strtolower(is_string($identity) ? $identity : '').'|'.$request->ip())];
        });
        RateLimiter::for('client-heartbeat', fn (Request $request) => Limit::perMinute(120)->by(hash('sha256', $request->bearerToken() ?? $request->ip() ?? 'unknown')));
    }
}
