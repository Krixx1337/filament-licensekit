<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Actions\CustomerLicenses;
use LicenseKit\Core\Actions\DeviceBindings;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\DeviceBinding;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Tests\Core\Fixtures\User;

test('removed customer endpoints stay absent even with stale configuration', function (bool $management) {
    $this->routingSettings = ['licensekit.management.enabled' => $management, 'licensekit.customer.enabled' => true, 'licensekit.routes.enabled' => true];
    $this->refreshApplication();
    app('migrator')->path(__DIR__.'/../Fixtures/migrations');
    app('migrator')->path(__DIR__.'/../../../vendor/laravel/sanctum/database/migrations');
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    expect(Route::has('licensekit.entitlements.index'))->toBeFalse();
    expect(Route::has('licensekit.management.products.index'))->toBe($management);
    $this->getJson('/api/licensekit/v1/entitlements')->assertNotFound();
    $this->getJson('/api/licensekit/v1/entitlements/1')->assertNotFound();
    $this->postJson('/api/licensekit/v1/license-claims', [])->assertNotFound();
    $this->postJson('/api/licensekit/v1/products/example/hwid-reset', [])->assertNotFound();
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    $key = app(CreateBatch::class)->create($plan, 1, 'php-customer', 'test')->keys->first();
    $entitlement = app(ClaimLicense::class)->claim($user, $key->key);
    expect(app(CustomerLicenses::class)->find($user, $entitlement->id)['status'])->toBe('active');
})->with([false, true]);

test('customer PHP summaries isolate owners and serialize without further queries', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $own = Entitlement::factory()->create(['user_id' => $user->id]);
    DeviceBinding::factory()->forEntitlement($own)->create();
    Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    $page = app(CustomerLicenses::class)->paginate($user);
    expect($page->total())->toBe(1);
    expect($page->items()[0])->toMatchArray(['id' => $own->id, 'device' => ['scope' => 'per_product', 'bound' => true, 'bound_at' => now()->toIso8601String()]]);
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

test('customer detail cannot disclose another owners entitlement', function () {
    $user = User::factory()->create();
    $other = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    expect(fn () => app(CustomerLicenses::class)->find($user, $other->id))->toThrow(ModelNotFoundException::class);
});

test('customer reset cannot mutate another owners device', function () {
    $user = User::factory()->create();
    $other = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    $binding = DeviceBinding::factory()->forEntitlement($other)->create();
    expect(fn () => app(DeviceBindings::class)->reset($other->user, $other->product, $user))->toThrow(LicensingException::class, 'reset forbidden');
    expect($binding->fresh()->hwid)->not->toBeNull();
    $this->assertDatabaseCount('licensekit_device_reset_history', 0);
});

test('customer summaries remain informational during product maintenance', function () {
    $user = User::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id]);
    $entitlement->product->update(['status' => 'maintenance']);
    expect(app(CustomerLicenses::class)->find($user, $entitlement->id)['status'])->toBe('active');
});

test('customer pagination eager loads summaries and retains page size', function () {
    $user = User::factory()->create();
    Entitlement::factory()->count(3)->create(['user_id' => $user->id]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $page = app(CustomerLicenses::class)->paginate($user, 1);
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();
    expect($queries->filter(fn (string $query) => str_contains($query, 'from "licensekit_products"')))->toHaveCount(1);
    expect($queries->filter(fn (string $query) => str_contains($query, 'from "licensekit_device_bindings"')))->toHaveCount(1);
    expect($page->nextPageUrl())->toContain('per_page=1');
});

test('license summary status follows intrinsic precedence', function (array $attributes, string $status) {
    $user = User::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id, ...$attributes]);
    $summary = app(CustomerLicenses::class)->find($user, $entitlement->id);
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
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id, 'expires_at' => now()]);
    expect(app(CustomerLicenses::class)->find($user, $entitlement->id)['status'])->toBe('expired');
});

test('customer PHP page size is bounded', function (int $size) {
    expect(fn () => app(CustomerLicenses::class)->paginate(User::factory()->create(), $size))->toThrow(LicensingException::class, 'invalid page size');
})->with([0, 101]);

test('customer pages have stable order and independent ownership', function () {
    $user = User::factory()->create();
    Entitlement::factory()->create(['user_id' => $user->id]);
    $second = Entitlement::factory()->create(['user_id' => $user->id]);
    Paginator::currentPageResolver(fn () => 2);
    $page = app(CustomerLicenses::class)->paginate($user, 1);
    expect($page->items()[0]['id'])->toBe($second->id);
    expect($page->total())->toBe(2);
});

test('reset summary agrees with mutation at cooldown boundary for expired licenses', function (bool $ready) {
    $this->freezeTime();
    $user = User::factory()->create();
    $entitlement = Entitlement::factory()->expired()->create(['user_id' => $user->id, 'status' => 'suspended']);
    $binding = DeviceBinding::factory()->forEntitlement($entitlement)->create(['last_hwid_reset_at' => now()->subDays(config('licensekit.device_binding.customer_reset.cooldown_days'))->addSeconds($ready ? 0 : 1)]);
    expect(app(CustomerLicenses::class)->find($user, $entitlement->id)['reset']['allowed'])->toBe($ready);
    if ($ready) {
        app(DeviceBindings::class)->reset($user, $entitlement->product, $user);
        expect($binding->fresh()->hwid)->toBeNull();
    } else {
        expect(fn () => app(DeviceBindings::class)->reset($user, $entitlement->product, $user))->toThrow(LicensingException::class, 'reset cooldown');
        expect($binding->fresh()->hwid)->not->toBeNull();
    }
})->with([true, false]);

test('unbound reset summary preserves cooldown precedence', function () {
    $user = User::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id]);
    $binding = DeviceBinding::factory()->forEntitlement($entitlement)->create(['hwid' => null, 'bound_at' => null, 'last_hwid_reset_at' => now()]);
    expect(app(CustomerLicenses::class)->find($user, $entitlement->id)['reset']['reason'])->toBe('reset_cooldown');
    expect(fn () => app(DeviceBindings::class)->reset($user, $entitlement->product, $user))->toThrow(LicensingException::class, 'reset cooldown');
    $binding->update(['last_hwid_reset_at' => null]);
    expect(app(CustomerLicenses::class)->find($user, $entitlement->id)['reset']['reason'])->toBe('device_not_bound');
    expect(fn () => app(DeviceBindings::class)->reset($user, $entitlement->product, $user))->toThrow(LicensingException::class, 'device not bound');
});

test('disabled customer reset rejects self service and still allows an admin override', function () {
    config(['licensekit.device_binding.customer_reset.enabled' => false, 'licensekit.device_binding.customer_reset.require_reason' => true]);
    $admin = User::factory()->create();
    $user = User::factory()->create();
    Gate::define('licensekit.admin', fn ($actor): bool => $actor->id === $admin->id);
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id]);
    $binding = DeviceBinding::factory()->forEntitlement($entitlement)->create();

    expect(app(CustomerLicenses::class)->find($user, $entitlement->id)['reset'])->toBe([
        'allowed' => false, 'available_at' => null, 'reason' => 'customer_reset_disabled',
    ]);
    try {
        app(DeviceBindings::class)->reset($user, $entitlement->product, $user);
        $this->fail('Customer reset must stay closed when self service is disabled.');
    } catch (LicensingException $exception) {
        expect($exception->error)->toBe('customer_reset_disabled')->and($exception->status)->toBe(403);
    }
    expect($binding->fresh()->hwid)->not->toBeNull();
    app(DeviceBindings::class)->adminReset($user, $entitlement->product, $admin, 'Support ticket');
    expect($binding->fresh()->hwid)->toBeNull();
});
