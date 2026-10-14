<?php

namespace LicenseKit;

use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\ServiceProvider;
use LicenseKit\Console\CheckInstallation;
use LicenseKit\Console\InstallLicenseKit;
use LicenseKit\Core\Contracts\ReadinessCheck;
use LicenseKit\Core\CoreServiceProvider;
use LicenseKit\Core\Http\Middleware\JsonResponse;
use LicenseKit\Core\Http\Middleware\RequireApiClient;
use LicenseKit\Http\Middleware\EnsureConfigured;
use LicenseKit\Modules\Outages\OutagesServiceProvider;
use LicenseKit\Support\ModuleReadiness;

class LicenseKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->app->configurationIsCached()) {
            $overrides = config('licensekit', []);
            if (is_array($overrides)) {
                config(['licensekit' => $this->mergeConfiguration(require __DIR__.'/../config/licensekit.php', $overrides)]);
            }
        }
        $this->app->bind(ReadinessCheck::class, ModuleReadiness::class);
        $this->app->register(CoreServiceProvider::class);
        $this->app->booting(function (): void {
            if (config('licensekit.modules.outages') === true) {
                $this->app->register(OutagesServiceProvider::class);
            }
        });
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/licensekit.php' => config_path('licensekit.php')], 'licensekit-config');
        $this->commands([InstallLicenseKit::class, CheckInstallation::class]);
        $this->callAfterResolving(HttpKernelContract::class, function (HttpKernel $kernel): void {
            $kernel->prependToMiddlewarePriority(EnsureConfigured::class);
            $kernel->prependToMiddlewarePriority(JsonResponse::class);
            $kernel->addToMiddlewarePriorityBefore(ThrottleRequests::class, RequireApiClient::class);
            $kernel->addToMiddlewarePriorityBefore(ThrottleRequestsWithRedis::class, RequireApiClient::class);
        });
    }

    /**
     * @param  array<string|int, mixed>  $defaults
     * @param  array<string|int, mixed>  $overrides
     * @return array<string|int, mixed>
     */
    private function mergeConfiguration(array $defaults, array $overrides): array
    {
        if (array_is_list($defaults) && array_is_list($overrides)) {
            return $overrides;
        }
        foreach ($overrides as $key => $value) {
            $defaults[$key] = is_array($value) && isset($defaults[$key]) && is_array($defaults[$key])
                ? $this->mergeConfiguration($defaults[$key], $value) : $value;
        }

        return $defaults;
    }
}
