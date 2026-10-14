<?php

use Illuminate\Support\Facades\Route;
use LicenseKit\Core\Http\Controllers\Management\BatchController;
use LicenseKit\Core\Http\Controllers\Management\CatalogController;
use LicenseKit\Core\Http\Middleware\RequireApiClient;
use LicenseKit\Http\Middleware\EnsureConfigured;
use LicenseKit\Support\SetupDiagnostics;

if (app(SetupDiagnostics::class)->managementErrors() !== []) {
    return;
}

Route::prefix(config('licensekit.management.prefix'))
    ->domain(config('licensekit.management.domain'))
    ->middleware(['api', 'licensekit.json', EnsureConfigured::class, ...config('licensekit.management.middleware'), RequireApiClient::class, 'throttle:licensekit.management'])
    ->group(function (): void {
        Route::get('products', [CatalogController::class, 'products'])->name('licensekit.management.products.index');
        Route::get('plans', [CatalogController::class, 'plans'])->name('licensekit.management.plans.index');
        Route::post('batches', [BatchController::class, 'store'])->name('licensekit.management.batches.store');
        Route::get('batches', [BatchController::class, 'index'])->name('licensekit.management.batches.index');
        Route::get('batches/{batch}', [BatchController::class, 'show'])->whereNumber('batch')->name('licensekit.management.batches.show');
        Route::get('batches/{batch}/keys', [BatchController::class, 'keys'])->whereNumber('batch')->name('licensekit.management.batches.keys');
    });
