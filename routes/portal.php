<?php

use Illuminate\Support\Facades\Route;
use LicenseKit\Core\Http\Controllers\PortalController;
use LicenseKit\Core\Users\ConfiguredUserModel;

Route::prefix(config('licensekit.portal_api_prefix'))->middleware(['web', 'auth:'.ConfiguredUserModel::guard(), 'licensekit.json'])
    ->group(function (): void {
        Route::get('licenses', [PortalController::class, 'index'])->name('licensekit.portal.licenses');
        Route::post('redeem', [PortalController::class, 'redeem'])->name('licensekit.portal.redeem');
        Route::post('licenses/{license}/reset-device', [PortalController::class, 'reset'])->whereNumber('license')->name('licensekit.portal.reset');
    });
