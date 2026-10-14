<?php

namespace LicenseKit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LicenseKit\Support\SetupDiagnostics;
use Symfony\Component\HttpFoundation\Response;

class EnsureConfigured
{
    public function __construct(private SetupDiagnostics $diagnostics) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $this->diagnostics->assertConfigured();

        return $next($request);
    }
}
