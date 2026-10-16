<?php

namespace LicenseKit\Core\Http\Middleware;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use LicenseKit\Core\Actions\ResponseSigning;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Http\Requests\LoaderRequest;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SignLoaderResponse
{
    public function __construct(private ResponseSigning $signing) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');
        $request->attributes->set('licensekit.http', true);
        try {
            $this->signing->publicKey();
        } catch (LicensingException) {
            return response()->json(['error' => 'signing_unavailable', 'message' => 'Run php artisan licensekit:install to configure response signing.'], 503);
        }
        try {
            $request->validate(LoaderRequest::nonceRules());
            $response = $next($request);
        } catch (Throwable $exception) {
            $handler = app(ExceptionHandler::class);
            $handler->report($exception);
            $response = $handler->render($request, $exception);
        }
        $data = json_decode($response->getContent(), true);
        $data = is_array($data) ? $data : ['error' => 'server_error', 'message' => 'Please try again later.'];
        if (isset($data['error'])) {
            $translated = __('licensekit::messages.'.$data['error']);
            if ($translated !== 'licensekit::messages.'.$data['error']) {
                $data['message'] = $translated;
            }
        }
        $body = json_encode([...$data, 'nonce' => is_string($request->input('nonce')) ? $request->input('nonce') : null,
            'server_time' => now()->timestamp], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $response->setContent($body);
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-LicenseKit-Signature', $this->signing->sign($body));

        return $response;
    }
}
