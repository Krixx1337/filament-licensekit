<?php

namespace LicenseKit\Modules\Outages;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use LicenseKit\Core\Events\EntitlementGranted;
use LicenseKit\Modules\Outages\Listeners\TrackGrantedEntitlement;
use LicenseKit\Support\Modules;

class OutagesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! Modules::enabled('outages')) {
            return;
        }
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations/outages');
        Event::listen(EntitlementGranted::class, TrackGrantedEntitlement::class);
    }
}
