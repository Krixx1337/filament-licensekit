<?php

use Illuminate\Support\Facades\Route;
use LicenseKit\Core\Http\Controllers\LoaderController;
use LicenseKit\Core\Http\Middleware\SignLoaderResponse;

Route::prefix(config('licensekit.client_prefix'))->middleware([SignLoaderResponse::class, 'api'])->group(function (): void {
    foreach (['login' => 'login', 'move-device' => 'moveDevice', 'heartbeat' => 'heartbeat', 'redeem' => 'redeem'] as $path => $action) {
        Route::post('{product:slug}/'.$path, [LoaderController::class, $action])
            ->middleware('throttle:licensekit.'.(in_array($path, ['login', 'move-device'], true) ? 'login' : 'session'))
            ->name('licensekit.client.'.$path);
    }
});
