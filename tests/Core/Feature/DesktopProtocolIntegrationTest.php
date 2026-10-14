<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use LicenseKit\Core\Actions\LicensedDevices;
use LicenseKit\Core\Actions\ManageLicensingBans;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\ApiClient;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\LicensingBan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Tests\Core\Fixtures\ClientSecurity;
use LicenseKit\Tests\Core\Fixtures\ClientSession;
use LicenseKit\Tests\Core\Fixtures\ClientSessions;
use LicenseKit\Tests\Core\Fixtures\User;

function bootWithoutDefaultDesktop(): void
{
    test()->desktop = false;
    test()->refreshApplication();
    app('migrator')->path(__DIR__.'/../Fixtures/migrations');
    app('migrator')->path(__DIR__.'/../../../vendor/laravel/sanctum/database/migrations');
    test()->artisan('migrate', ['--force' => true])->assertSuccessful();
}

test('host can disable its client example while preserving package APIs', function () {
    bootWithoutDefaultDesktop();
    expect(Route::has('licensekit.desktop.login'))->toBeFalse()->and(Route::has('licensekit.desktop.heartbeat'))->toBeFalse();
    foreach (['licensekit.entitlements.index', 'licensekit.license-claims.store', 'licensekit.hwid-reset.store'] as $name) {
        expect(Route::has($name))->toBeFalse();
    }
    $user = User::factory()->create();
    $product = Product::factory()->create();
    expect(fn () => app(ClientSessions::class)->login($product, $user->email, 'password', desktopRequest($user)['hwid']))
        ->toThrow(LicensingException::class, 'module disabled');
    expect(fn () => app(ClientSessions::class)->heartbeat($product, 'token', desktopRequest($user)['hwid']))
        ->toThrow(LicensingException::class, 'module disabled');
    $this->assertDatabaseCount('licensekit_device_bindings', 0);
    $this->assertDatabaseCount('client_sessions', 0);
});

test('host controller grants and validates without default desktop tokens and reset revokes host sessions', function () {
    bootWithoutDefaultDesktop();
    installHostDesktopProtocol();
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $token = $this->postJson('host/products/'.$product->slug.'/login', desktopRequest($user))->assertOk()->json('host_token');
    $this->assertDatabaseCount('licensekit_device_bindings', 1);
    $this->assertDatabaseCount('host_desktop_sessions', 1);
    $this->assertDatabaseCount('client_sessions', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->withToken($token)->postJson('host/products/'.$product->slug.'/heartbeat', desktopRequest($user))->assertOk()->assertJsonPath('host_valid', true);
    $expiry = DB::table('host_desktop_sessions')->value('expires_at');
    $this->withToken($token)->postJson('host/products/'.$product->slug.'/heartbeat', desktopRequest($user, 'other'))->assertForbidden()->assertJsonPath('error', 'hwid_mismatch');
    expect(DB::table('host_desktop_sessions')->value('expires_at'))->toBe($expiry);
    app(LicensedDevices::class)->reset($entitlement, $user);
    $this->assertDatabaseCount('host_desktop_sessions', 0);
    $this->withToken($token)->postJson('host/products/'.$product->slug.'/heartbeat', desktopRequest($user))->assertUnauthorized();
});

test('custom protocol access denies revoked entitlements without creating bindings or credentials', function () {
    bootWithoutDefaultDesktop();
    installHostDesktopProtocol();
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'is_revoked' => true]);
    $this->postJson('host/products/'.$product->slug.'/login', desktopRequest($user))->assertForbidden()->assertJsonPath('error', 'license_unavailable');
    $this->assertDatabaseCount('licensekit_device_bindings', 0);
    $this->assertDatabaseCount('host_desktop_sessions', 0);
});

test('host protocol session cleanup remains active when default desktop is disabled', function () {
    bootWithoutDefaultDesktop();
    installHostDesktopProtocol();
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $this->postJson('host/products/'.$product->slug.'/login', desktopRequest($user))->assertOk();
    $user->update(['password' => 'new-password']);
    $this->assertDatabaseCount('host_desktop_sessions', 0);
});

test('session continuation failure rolls back binding and host credential writes', function () {
    installHostDesktopProtocol();
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    expect(fn () => DB::transaction(function () use ($user, $product): void {
        $context = app(LicensedDevices::class)->bind($user, $product, desktopRequest($user)['hwid']);
        DB::table('host_desktop_sessions')->insert(['user_id' => $user->getKey(), 'product_id' => $context->access->product->id, 'token_hash' => 'hash',
            'fingerprint' => app(ClientSecurity::class)->fingerprint($user), 'hwid' => '{}', 'expires_at' => now()]);
        throw new RuntimeException('host session failed');
    }))->toThrow(RuntimeException::class, 'host session failed');
    $this->assertDatabaseCount('licensekit_device_bindings', 0);
    $this->assertDatabaseCount('host_desktop_sessions', 0);
});

test('host tokens use host session namespace and remain isolated from integration tokens', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $token = app(ClientSessions::class)->login($product, $user->email, 'password', desktopRequest($user)['hwid'])['token'];
    $integration = ApiClient::factory()->state(['all_products' => true, 'permissions' => ['licenses:create', 'keys:read']])->create();
    $unrelated = $integration->createToken('unrelated', ['custom:purpose'])->accessToken;
    $this->assertDatabaseHas('personal_access_tokens', ['tokenable_type' => ClientSession::class]);
    $this->withToken($token)->postJson(route('licensekit.desktop.heartbeat', $product->slug), desktopRequest($user))->assertOk();
    $this->assertDatabaseCount('personal_access_tokens', 2);
    expect($unrelated->fresh()->tokenable_type)->toBe(ApiClient::class);
});

test('default session cleanup and termination work after disabling desktop routes', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    app(ClientSessions::class)->login($product, $user->email, 'password', desktopRequest($user)['hwid']);
    config(['host_client.enabled' => false]);
    $user->update(['password' => 'new-password']);
    $this->assertDatabaseCount('client_sessions', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    config(['host_client.enabled' => true]);
    app(ClientSessions::class)->login($product, $user->email, 'new-password', desktopRequest($user)['hwid']);
    config(['host_client.enabled' => false, 'licensekit.modules.downloads' => true]);
    app(ClientSessions::class)->terminate($entitlement, 'test', 'Terminate inactive protocol');
    $this->assertDatabaseCount('client_sessions', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->assertDatabaseCount('licensekit_device_bindings', 1);
});

test('custom protocol rechecks restrictions and credential fingerprints without extending denied sessions', function () {
    bootWithoutDefaultDesktop();
    installHostDesktopProtocol();
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $token = $this->postJson('host/products/'.$product->slug.'/login', desktopRequest($user))->assertOk()->json('host_token');
    $expiry = DB::table('host_desktop_sessions')->value('expires_at');
    $entitlement->update(['is_blocked' => true]);
    $this->withToken($token)->postJson('host/products/'.$product->slug.'/heartbeat', desktopRequest($user))->assertForbidden()->assertJsonPath('error', 'service_unavailable');
    expect(DB::table('host_desktop_sessions')->value('expires_at'))->toBe($expiry);
    $entitlement->update(['is_blocked' => false]);
    User::whereKey($user->id)->update(['password' => password_hash('changed-password', PASSWORD_BCRYPT)]);
    $this->withToken($token)->postJson('host/products/'.$product->slug.'/heartbeat', desktopRequest($user))->assertUnauthorized();
    expect(DB::table('host_desktop_sessions')->value('expires_at'))->toBe($expiry);
});

test('ban invalidates all participating custom sessions including multiple instances without changing device or clocks', function () {
    bootWithoutDefaultDesktop();
    installHostDesktopProtocol();
    $admin = User::factory()->create();
    Gate::define('licensekit.manage', fn ($actor): bool => $actor->getKey() === $admin->id);
    $user = User::factory()->create();
    $other = User::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id]);
    $unrelated = Entitlement::factory()->create(['user_id' => $other->id]);
    $token = $this->postJson('host/products/'.$entitlement->product->slug.'/login', desktopRequest($user))->assertOk()->json('host_token');
    $session = (array) DB::table('host_desktop_sessions')->first();
    unset($session['id']);
    DB::table('host_desktop_sessions')->insert([...$session, 'token_hash' => hash('sha256', 'second-instance')]);
    $otherToken = $this->postJson('host/products/'.$unrelated->product->slug.'/login', desktopRequest($other))->assertOk()->json('host_token');
    $before = $entitlement->fresh()->getAttributes();
    $ban = app(ManageLicensingBans::class)->create($user, $entitlement->product, 'Abuse', $admin);
    $this->assertDatabaseCount('host_desktop_sessions', 1);
    expect($entitlement->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('licensekit_device_bindings', 2);
    $this->postJson('host/products/'.$entitlement->product->slug.'/login', desktopRequest($user))->assertForbidden()->assertJsonPath('error', 'license_banned');
    $this->withToken($token)->postJson('host/products/'.$entitlement->product->slug.'/heartbeat', desktopRequest($user))->assertUnauthorized();
    $this->withToken($otherToken)->postJson('host/products/'.$unrelated->product->slug.'/heartbeat', desktopRequest($other))->assertOk();
    app(ManageLicensingBans::class)->lift($ban, 'Appeal', $admin);
    $this->postJson('host/products/'.$entitlement->product->slug.'/login', desktopRequest($user))->assertOk();
});

test('shared verification denies bans before custom renewal', function () {
    bootWithoutDefaultDesktop();
    installHostDesktopProtocol();
    $user = User::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id]);
    $token = $this->postJson('host/products/'.$entitlement->product->slug.'/login', desktopRequest($user))->assertOk()->json('host_token');
    $expiry = DB::table('host_desktop_sessions')->value('expires_at');
    LicensingBan::factory()->create(['user_id' => $user->id, 'product_id' => $entitlement->product_id]);
    $this->travel(1)->minutes();
    $this->withToken($token)->postJson('host/products/'.$entitlement->product->slug.'/heartbeat', desktopRequest($user))->assertForbidden()
        ->assertExactJson(['error' => 'license_banned', 'message' => 'license banned']);
    expect(DB::table('host_desktop_sessions')->value('expires_at'))->toBe($expiry);
});
