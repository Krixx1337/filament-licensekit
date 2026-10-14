<?php

use Illuminate\Support\Facades\Event;
use LicenseKit\Core\Actions\CustomerLicenses;
use LicenseKit\Core\Actions\DeviceBindings;
use LicenseKit\Core\Events\ProductAccessInvalidated;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\DeviceBinding;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\Product;
use LicenseKit\Support\ModuleReadiness;
use LicenseKit\Tests\Core\Fixtures\ClientSession;
use LicenseKit\Tests\Core\Fixtures\ClientSessions;
use LicenseKit\Tests\Core\Fixtures\User;

function scopedLogin(User $user, Product $product, string $pc = 'desktop'): string
{
    return app(ClientSessions::class)->login($product, $user->email, 'password', desktopRequest($user, $pc)['hwid'])['token'];
}

test('per product devices permit different PCs while global devices reject another PC', function (string $scope) {
    config(['licensekit.device_binding.scope' => $scope]);
    $user = User::factory()->create();
    $first = Entitlement::factory()->create(['user_id' => $user->id]);
    $second = Entitlement::factory()->create(['user_id' => $user->id]);
    scopedLogin($user, $first->product, 'pc-a');

    if ($scope === 'global') {
        expect(fn () => scopedLogin($user, $second->product, 'pc-b'))->toThrow(LicensingException::class, 'hwid mismatch');
        scopedLogin($user, $second->product, 'pc-a');
        $this->assertDatabaseCount('licensekit_device_bindings', 1);
    } else {
        scopedLogin($user, $second->product, 'pc-b');
        $this->assertDatabaseCount('licensekit_device_bindings', 2);
    }
    $this->assertDatabaseCount('client_sessions', 2);
})->with(['per_product', 'global']);

test('global reset clears all product sessions and cannot bypass cooldown through another product', function () {
    $this->freezeTime();
    config(['licensekit.device_binding.scope' => 'global', 'licensekit.device_binding.customer_reset.cooldown_days' => 7]);
    $user = User::factory()->create();
    $first = Entitlement::factory()->create(['user_id' => $user->id]);
    $second = Entitlement::factory()->create(['user_id' => $user->id]);
    scopedLogin($user, $first->product);
    scopedLogin($user, $second->product);
    expect(app(CustomerLicenses::class)->find($user, $first->id)['device'])->toMatchArray(['scope' => 'global', 'bound' => true]);
    expect(app(CustomerLicenses::class)->find($user, $second->id)['device'])->toMatchArray(['scope' => 'global', 'bound' => true]);

    app(DeviceBindings::class)->reset($user, $first->product, $user);

    $this->assertDatabaseCount('client_sessions', 0);
    expect(DeviceBinding::first()->hwid)->toBeNull();
    scopedLogin($user, $second->product, 'laptop');
    expect(fn () => app(DeviceBindings::class)->reset($user, $second->product, $user))->toThrow(LicensingException::class, 'reset cooldown');
    $this->travel(7)->days();
    app(DeviceBindings::class)->reset($user, $second->product, $user);
    expect(DeviceBinding::first()->hwid)->toBeNull();
});

test('per product reset retains other product binding and session', function () {
    $user = User::factory()->create();
    $first = Entitlement::factory()->create(['user_id' => $user->id]);
    $second = Entitlement::factory()->create(['user_id' => $user->id]);
    scopedLogin($user, $first->product);
    $token = scopedLogin($user, $second->product, 'other');

    app(DeviceBindings::class)->reset($user, $first->product, $user);

    $this->assertDatabaseCount('client_sessions', 1);
    expect($second->fresh()->binding->hwid)->not->toBeNull();
    app(ClientSessions::class)->heartbeat($second->product, $token, desktopRequest($user, 'other')['hwid']);
});

test('diagnostics report scope flips without changing stored devices or credentials', function (string $scope, string $other) {
    config(['licensekit.device_binding.scope' => $scope]);
    $user = User::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id]);
    scopedLogin($user, $entitlement->product);
    $original = DeviceBinding::first()->getAttributes();
    config(['licensekit.device_binding.scope' => $other]);

    expect(app(ModuleReadiness::class)->issues())->toContain('Device bindings use another scope. Restore licensekit.device_binding.scope; automatic conversion/reset is not supported.');

    expect(DeviceBinding::first()->getAttributes())->toBe($original);
    $this->assertDatabaseCount('client_sessions', 1);
    $this->artisan('licensekit:check')->expectsOutputToContain('Device bindings use another scope')->assertFailed();
})->with([['per_product', 'global'], ['global', 'per_product']]);

test('independent session heartbeat updates only presented credential and invalidation removes every matching session', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id]);
    $token = scopedLogin($user, $entitlement->product);
    $first = ClientSession::first();
    $second = ClientSession::factory()->create(['user_id' => $user->id, 'product_id' => $entitlement->product_id, 'hwid' => $first->hwid, 'user_fingerprint' => $first->user_fingerprint]);
    $otherToken = $second->createToken('desktop:'.$entitlement->product_id, ['desktop:heartbeat:'.$entitlement->product_id], now()->addMinutes(10));
    $second->update(['personal_access_token_id' => $otherToken->accessToken->id]);
    $this->travel(1)->minute();

    app(ClientSessions::class)->heartbeat($entitlement->product, $token, desktopRequest($user)['hwid']);

    expect($otherToken->accessToken->fresh()->expires_at->toIso8601String())->toBe(now()->addMinutes(9)->toIso8601String());
    app(DeviceBindings::class)->reset($user, $entitlement->product, $user);
    $this->assertDatabaseCount('client_sessions', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('failed global reset listener rolls back device change and all session cleanup', function () {
    config(['licensekit.device_binding.scope' => 'global']);
    $user = User::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id]);
    scopedLogin($user, $entitlement->product);
    Event::listen(ProductAccessInvalidated::class, fn () => throw new RuntimeException('listener failed'));

    expect(fn () => app(DeviceBindings::class)->reset($user, $entitlement->product, $user))->toThrow(RuntimeException::class, 'listener failed');

    expect(DeviceBinding::first()->hwid)->not->toBeNull();
    expect(DeviceBinding::first()->last_hwid_reset_at)->toBeNull();
    $this->assertDatabaseCount('client_sessions', 1);
    $this->assertDatabaseCount('personal_access_tokens', 1);
});

test('global binding rejects another product device without replacing binding or session', function () {
    config(['licensekit.device_binding.scope' => 'global']);
    $user = User::factory()->create();
    $first = Entitlement::factory()->create(['user_id' => $user->id]);
    $second = Entitlement::factory()->create(['user_id' => $user->id]);
    scopedLogin($user, $first->product);
    $binding = DeviceBinding::first()->hwid;

    expect(fn () => app(ClientSessions::class)->login($second->product, $user->email, 'password', ['value' => 'another-device']))->toThrow(LicensingException::class, 'hwid mismatch');

    expect(DeviceBinding::first()->hwid)->toBe($binding);
    $this->assertDatabaseCount('client_sessions', 1);
});

test('diagnostics detect another scope even after clearing a device', function () {
    $user = User::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id]);
    scopedLogin($user, $entitlement->product);
    app(DeviceBindings::class)->reset($user, $entitlement->product, $user);
    config(['licensekit.device_binding.scope' => 'global']);

    expect(app(ModuleReadiness::class)->issues())->toContain('Device bindings use another scope. Restore licensekit.device_binding.scope; automatic conversion/reset is not supported.');

    expect(DeviceBinding::first()->last_hwid_reset_at)->not->toBeNull();
    $this->assertDatabaseCount('client_sessions', 0);
});
