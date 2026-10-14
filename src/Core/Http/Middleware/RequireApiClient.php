<?php

namespace LicenseKit\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LicenseKit\Core\Actions\ManagementAccess;
use Symfony\Component\HttpFoundation\Response;

class RequireApiClient
{
    public function __construct(private ManagementAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $context = $this->access->authenticate($request->bearerToken() ?? '');
        $request->attributes->set('licensekit.management_client_id', $context->client->id);

        return $next($request);
    }
}
