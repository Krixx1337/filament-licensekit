<?php

use LicenseKit\Core\Actions\LicensedDevices;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\ApiClient;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\Product;
use LicenseKit\Tests\Core\Fixtures\ClientSessions;
use LicenseKit\Tests\Core\Fixtures\User;

test('desktop credentials use host users without token traits or username fields', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);

    $response = $this->postJson(route('licensekit.desktop.login', $product->slug), desktopRequest($user))->assertOk();

    expect($response->json('data.token'))->toBeString();
    $license = Entitlement::where('user_id', $user->id)->where('product_id', $product->id)->sole();
    expect($license->device)->toBe('desktop');
    expect($license->toArray())->not->toHaveKey('device');
    expect($response->getContent())->not->toContain('"hwid"');
});

test('new login creates independent same-product session without affecting another product', function () {
    $user = User::factory()->create();
    $products = Product::factory()->count(2)->create();
    foreach ($products as $product) {
        Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    }
    $first = $this->postJson(route('licensekit.desktop.login', $products[0]->slug), desktopRequest($user))->json('data.token');
    $other = $this->postJson(route('licensekit.desktop.login', $products[1]->slug), desktopRequest($user, 'laptop'))->json('data.token');
    $replacement = $this->postJson(route('licensekit.desktop.login', $products[0]->slug), desktopRequest($user))->json('data.token');

    $this->withToken($first)->postJson(route('licensekit.desktop.heartbeat', $products[0]->slug), desktopRequest($user))->assertOk();
    $this->withToken($replacement)->postJson(route('licensekit.desktop.heartbeat', $products[0]->slug), desktopRequest($user))->assertOk();
    $this->withToken($other)->postJson(route('licensekit.desktop.heartbeat', $products[1]->slug), desktopRequest($user, 'laptop'))->assertOk();
    $this->withToken($replacement)->postJson(route('licensekit.desktop.heartbeat', $products[1]->slug), desktopRequest($user))->assertUnauthorized();

    $this->assertDatabaseCount('client_sessions', 3);
});

test('heartbeat refreshes session expiry but rejects expired tokens', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $token = $this->postJson(route('licensekit.desktop.login', $product->slug), desktopRequest($user))->json('data.token');
    $this->travel(10)->minutes();

    $this->withToken($token)->postJson(route('licensekit.desktop.heartbeat', $product->slug), desktopRequest($user))->assertOk();
    $this->travel(16)->minutes();
    $this->postJson(route('licensekit.desktop.heartbeat', $product->slug), desktopRequest($user))->assertUnauthorized();
});

test('different device cannot login or heartbeat against existing binding', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $token = $this->postJson(route('licensekit.desktop.login', $product->slug), desktopRequest($user))->json('data.token');

    $this->postJson(route('licensekit.desktop.login', $product->slug), desktopRequest($user, 'other'))->assertForbidden()->assertJsonPath('error', 'hwid_mismatch');
    $this->withToken($token)->postJson(route('licensekit.desktop.heartbeat', $product->slug), desktopRequest($user, 'other'))->assertForbidden();

    expect(Entitlement::sole()->device)->toBe('desktop');
    $this->assertDatabaseCount('client_sessions', 1);
});

test('password changes invalidate every desktop product session', function () {
    $user = User::factory()->create();
    $products = Product::factory()->count(2)->create();
    foreach ($products as $product) {
        Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
        $this->postJson(route('licensekit.desktop.login', $product->slug), desktopRequest($user))->assertOk();
    }

    $user->update(['password' => 'new-password']);

    $this->assertDatabaseCount('client_sessions', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('customer reset invalidates session and cooldown applies only to selected product', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $this->postJson(route('licensekit.desktop.login', $product->slug), desktopRequest($user))->assertOk();

    app(LicensedDevices::class)->reset($user, $product);
    expect($entitlement->fresh()->device)->toBeNull();
    $this->assertDatabaseCount('client_sessions', 0);
    app(ClientSessions::class)->login($product, $user->email, 'password', desktopRequest($user, 'laptop')['hwid']);
    expect(fn () => app(LicensedDevices::class)->reset($user, $product))->toThrow(LicensingException::class, 'reset cooldown');
    expect($entitlement->fresh()->device_reset_at)->not->toBeNull();
});

test('integration token cannot heartbeat and desktop token cannot issue keys', function () {
    $client = ApiClient::factory()->state(['all_products' => true, 'permissions' => ['licenses:create', 'keys:read']])->create();
    $integration = $client->createToken('licensekit:api', ['*'], now()->addDay())->plainTextToken;
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $token = $this->postJson(route('licensekit.desktop.login', $product->slug), desktopRequest($user))->json('data.token');

    $this->withToken($integration)->postJson(route('licensekit.desktop.heartbeat', $product->slug), desktopRequest($user))->assertUnauthorized();
    $this->withToken($token)->postJson('/api/licensekit/v1/licenses/issue', ['plan_id' => 1, 'quantity' => 1, 'reference' => 'sale'])->assertNotFound();
});

test('desktop access denies unavailable user product and entitlement states', function (string $state, string $error) {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    match ($state) {
        'suspended' => $user->forceFill(['is_suspended' => true])->save(),
        'unverified' => $user->forceFill(['email_verified_at' => null])->save(),
        'revoked' => $entitlement->update(['status' => 'revoked']),
        'expired' => $entitlement->update(['expires_at' => now()->subSecond()]),
        'blocked' => $product->update(['status' => 'maintenance']),
    };

    $response = $this->postJson(route('licensekit.desktop.login', $product->slug), desktopRequest($user));

    $response->assertJsonPath('error', $error);
    $this->assertDatabaseCount('client_sessions', 0);
})->with(['suspended' => ['suspended', 'invalid_credentials'], 'unverified' => ['unverified', 'user_unavailable'],
    'revoked' => ['revoked', 'license_unavailable'], 'expired' => ['expired', 'license_expired'], 'blocked' => ['blocked', 'service_unavailable']]);

test('heartbeat denies token exactly at expiry and rechecks revoked license', function () {
    $this->travelTo(now()->setDate(2026, 1, 1)->startOfDay());
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $token = $this->postJson(route('licensekit.desktop.login', $product->slug), desktopRequest($user))->json('data.token');

    $entitlement->update(['status' => 'revoked']);
    $this->withToken($token)->postJson(route('licensekit.desktop.heartbeat', $product->slug), desktopRequest($user))->assertForbidden()->assertJsonPath('error', 'license_unavailable');
    $entitlement->update(['status' => 'active']);
    $this->travel(15)->minutes();
    $this->postJson(route('licensekit.desktop.heartbeat', $product->slug), desktopRequest($user))->assertUnauthorized();
});

test('malformed login identity returns 422 rather than crashing rate limiter', function () {
    $product = Product::factory()->create();

    $this->postJson(route('licensekit.desktop.login', $product->slug), ['login' => ['invalid'], 'password' => 'password', 'hwid' => []])->assertUnprocessable()->assertJsonValidationErrors('login');

    $this->assertDatabaseCount('client_sessions', 0);
});
