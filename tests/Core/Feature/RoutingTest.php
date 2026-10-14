<?php

use Illuminate\Support\Facades\Route;
use LicenseKit\Core\Actions\CustomerLicenses;
use LicenseKit\Core\Models\ApiClient;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Tests\Core\Fixtures\User;

function bootRouting(array $settings): void
{
    test()->routingSettings = ['app.url' => 'http://localhost', ...$settings];
    test()->refreshApplication();
    app('migrator')->path(__DIR__.'/../Fixtures/migrations');
    app('migrator')->path(__DIR__.'/../../../vendor/laravel/sanctum/database/migrations');
    test()->artisan('migrate', ['--force' => true])->assertSuccessful();
}

test('management placement uses its complete prefix domain and custom middleware', function () {
    bootRouting(['licensekit.management.enabled' => true, 'licensekit.management.prefix' => 'operations', 'licensekit.management.domain' => 'ops.example.test', 'licensekit.management.middleware' => ['auth']]);
    $route = Route::getRoutes()->getByName('licensekit.management.products.index');
    expect($route->uri())->toBe('operations/products');
    expect($route->getDomain())->toBe('ops.example.test');
    $client = ApiClient::factory()->create(['all_products' => true, 'permissions' => []]);
    $token = $client->createToken('licensekit:api', ['licensekit:api'], now()->addHour())->plainTextToken;
    $this->withToken($token)->getJson('http://ops.example.test/operations/products')->assertUnauthorized();
    $this->actingAs(User::factory()->create())->getJson('http://ops.example.test/operations/products')->assertOk();
    $this->getJson('http://localhost/api/licensekit/v1/manage/products')->assertNotFound();
});

test('customer PHP services work with package HTTP disabled and no panel', function () {
    bootRouting(['licensekit.management.enabled' => false]);
    expect(Route::has('licensekit.management.products.index'))->toBeFalse();
    $user = User::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id]);
    expect(app(CustomerLicenses::class)->find($user, $entitlement->id)['id'])->toBe($entitlement->id);
});

test('obsolete routing overrides do not change management registration', function () {
    bootRouting(['licensekit.management.enabled' => true, 'licensekit.routes.enabled' => false, 'licensekit.routes.prefix' => 'obsolete', 'licensekit.management.routes.prefix' => 'legacy']);
    $this->getJson('/api/licensekit/v1/manage/products')->assertUnauthorized();
    $this->getJson('/legacy/products')->assertNotFound();
    $this->getJson('/obsolete/manage/products')->assertNotFound();
});
