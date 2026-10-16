<?php

use Illuminate\Support\Facades\Route;

test('client and store routes honor flat prefixes and off switches', function () {
    $this->routingSettings = ['licensekit.client_enabled' => false, 'licensekit.store_api_enabled' => true, 'licensekit.store_api_prefix' => 'operations'];
    $this->refreshApplication();
    expect(Route::has('licensekit.client.login'))->toBeFalse();
    expect(Route::getRoutes()->getByName('licensekit.management.products.index')->uri())->toBe('operations/products');
    $this->getJson('/operations/products')->assertUnauthorized();
    $this->getJson('/api/licensekit/store/products')->assertNotFound();
});

test('portal JSON routes are off by default', function () {
    expect(Route::has('licensekit.portal.licenses'))->toBeFalse();
});
