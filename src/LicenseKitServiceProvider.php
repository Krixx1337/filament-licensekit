<?php

namespace LicenseKit;

use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use LicenseKit\Console\CheckInstallation;
use LicenseKit\Console\GenerateOpenApi;
use LicenseKit\Console\InstallLicenseKit;
use LicenseKit\Core\CoreServiceProvider;
use LicenseKit\Core\Http\Middleware\JsonResponse;
use LicenseKit\Core\Http\Middleware\RequireApiConnection;
use LicenseKit\Filament\AdminAccess;

class LicenseKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/licensekit.php', 'licensekit');
        $this->app->register(CoreServiceProvider::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/licensekit.php' => config_path('licensekit.php')], 'licensekit-config');
        $this->commands([InstallLicenseKit::class, CheckInstallation::class, GenerateOpenApi::class]);
        // Defaults: every panel user is an admin and nobody is support-only. Hosts redefine these gates.
        if (! Gate::has(AdminAccess::ADMIN)) {
            Gate::define(AdminAccess::ADMIN, fn (Model $user): bool => true);
        }
        if (! Gate::has(AdminAccess::SUPPORT)) {
            Gate::define(AdminAccess::SUPPORT, fn (Model $user): bool => false);
        }
        $this->callAfterResolving(HttpKernelContract::class, function (HttpKernel $kernel): void {
            $kernel->prependToMiddlewarePriority(JsonResponse::class);
            $kernel->addToMiddlewarePriorityBefore(ThrottleRequests::class, RequireApiConnection::class);
            $kernel->addToMiddlewarePriorityBefore(ThrottleRequestsWithRedis::class, RequireApiConnection::class);
        });
    }
}
