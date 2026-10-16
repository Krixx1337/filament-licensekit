<?php

namespace LicenseKit\Filament\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use LicenseKit\Filament\Pages\SetupNeeded;
use LicenseKit\Support\SetupDiagnostics;
use Symfony\Component\HttpFoundation\Response;

class EnsurePanelSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = \Filament\Support\original_request()->path();
        if (str_contains($path, '/licensekit/') && ! str_ends_with($path, '/licensekit/setup')) {
            $diagnostics = app(SetupDiagnostics::class);
            $issues = $diagnostics->issues();
            if ($issues === []) {
                $error = $diagnostics->panelError(Filament::getCurrentPanel());
                if ($error !== null) {
                    $issues[] = $error;
                }
            }
            if ($issues !== []) {
                return redirect(SetupNeeded::getUrl());
            }
        }

        return $next($request);
    }
}
