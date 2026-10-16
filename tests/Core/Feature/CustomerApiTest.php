<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Actions\CustomerLicenses;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Tests\Core\Fixtures\User;

test('removed customer endpoints stay absent even with stale configuration', function (bool $management) {
    $this->routingSettings = ['licensekit.management.enabled' => $management, 'licensekit.customer.enabled' => true, 'licensekit.routes.enabled' => true];
    $this->refreshApplication();
    app('migrator')->path(__DIR__.'/../Fixtures/migrations');
    app('migrator')->path(__DIR__.'/../../../vendor/laravel/sanctum/database/migrations');
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    expect(Route::has('licensekit.licenses.index'))->toBeFalse();
    expect(Route::has('licensekit.management.products.index'))->toBe($management);
    $this->getJson('/api/licensekit/v1/licenses')->assertNotFound();
    $this->getJson('/api/licensekit/v1/licenses/1')->assertNotFound();
    $this->postJson('/api/licensekit/v1/license-claims', [])->assertNotFound();
    $this->postJson('/api/licensekit/v1/products/example/hwid-reset', [])->assertNotFound();
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    $key = app(CreateBatch::class)->create($plan, 1, 'php-customer', 'test')->keys->first();
    $license = app(ClaimLicense::class)->claim($user, $key->key);
    expect(app(CustomerLicenses::class)->find($user, $license->id)['status'])->toBe('active');
})->with([false, true]);

test('customer PHP summaries isolate owners and serialize without further queries', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $own = License::factory()->create(['user_id' => $user->id, 'device' => 'pc', 'device_bound_at' => now()]);
    License::factory()->create(['user_id' => User::factory()->create()->id]);
    $page = app(CustomerLicenses::class)->paginate($user);
    expect($page->total())->toBe(1);
    expect($page->items()[0])->toMatchArray(['id' => $own->id, 'device' => ['bound' => true, 'bound_at' => now()->toIso8601String()]]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $json = $page->toJson();
    expect($json)->not->toContain('hwid')->not->toContain('user_id')->not->toContain('value_hash');
    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
});

test('customer reads recheck current suspension and verification', function (array $attributes) {
    $user = User::factory()->create();
    $user->newQuery()->whereKey($user->id)->update($attributes);
    expect(fn () => app(CustomerLicenses::class)->paginate($user))->toThrow(LicensingException::class, 'user unavailable');
})->with([[['is_suspended' => true]], [['email_verified_at' => null]]]);

test('customer detail cannot disclose another owners license', function () {
    $user = User::factory()->create();
    $other = License::factory()->create(['user_id' => User::factory()->create()->id]);
    expect(fn () => app(CustomerLicenses::class)->find($user, $other->id))->toThrow(ModelNotFoundException::class);
});

test('customer summaries remain informational during product maintenance', function () {
    $user = User::factory()->create();
    $license = License::factory()->create(['user_id' => $user->id]);
    $license->product->update(['status' => 'maintenance']);
    expect(app(CustomerLicenses::class)->find($user, $license->id)['status'])->toBe('active');
});

test('customer pagination eager loads summaries and retains page size', function () {
    $user = User::factory()->create();
    License::factory()->count(3)->create(['user_id' => $user->id]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $page = app(CustomerLicenses::class)->paginate($user, 1);
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();
    expect($queries->filter(fn (string $query) => str_contains($query, 'from "licensekit_products"')))->toHaveCount(1);
    expect($page->nextPageUrl())->toContain('per_page=1');
});

test('license summary status follows intrinsic precedence', function (array $attributes, string $status) {
    $user = User::factory()->create();
    $license = License::factory()->create(['user_id' => $user->id, ...$attributes]);
    $summary = app(CustomerLicenses::class)->find($user, $license->id);
    expect($summary['status'])->toBe($status);
    expect($summary)->not->toHaveKey('timer_paused');
})->with([
    [[], 'active'], [['status' => 'revoked'], 'revoked'],
    [['status' => 'suspended', 'expires_at' => '2020-01-01'], 'suspended'],
    [['expires_at' => '2020-01-01'], 'expired'], [['is_lifetime' => true, 'expires_at' => null], 'active'],
]);

test('expiry exactly now is expired', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $license = License::factory()->create(['user_id' => $user->id, 'expires_at' => now()]);
    expect(app(CustomerLicenses::class)->find($user, $license->id)['status'])->toBe('expired');
});

test('customer PHP page size is bounded', function (int $size) {
    expect(fn () => app(CustomerLicenses::class)->paginate(User::factory()->create(), $size))->toThrow(LicensingException::class, 'invalid page size');
})->with([0, 101]);

test('customer pages have stable order and independent ownership', function () {
    $user = User::factory()->create();
    License::factory()->create(['user_id' => $user->id]);
    $second = License::factory()->create(['user_id' => $user->id]);
    Paginator::currentPageResolver(fn () => 2);
    $page = app(CustomerLicenses::class)->paginate($user, 1);
    expect($page->items()[0]['id'])->toBe($second->id);
    expect($page->total())->toBe(2);
});
