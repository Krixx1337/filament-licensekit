<?php

namespace LicenseKit\Tests\Filament\Fixtures;

use Filament\Http\Middleware\Authenticate;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use LicenseKit\LicenseKitPlugin;

class FullPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('full')->path('full')->authGuard('staff')->login()->plugin(LicenseKitPlugin::make())
            ->middleware([StartSession::class, ShareErrorsFromSession::class, SubstituteBindings::class])->authMiddleware([Authenticate::class]);
    }
}
