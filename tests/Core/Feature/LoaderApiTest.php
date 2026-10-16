<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use LicenseKit\Core\Actions\DeviceBans;
use LicenseKit\Core\Actions\LicensedDevices;
use LicenseKit\Core\Actions\ManageLicenses;
use LicenseKit\Core\Actions\ResponseSigning;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LoaderSession;
use LicenseKit\Core\Models\Product;
use LicenseKit\Tests\Core\Fixtures\User;

beforeEach(function () {
    config(['licensekit.signing_key_path' => storage_path('framework/testing-signing.key')]);
    app(ResponseSigning::class)->install();
});

function loaderPayload(array $data = []): array
{
    return ['nonce' => str_repeat('a', 64), ...$data];
}

function loaderLogin(User $user, Product $product, string $device = 'pc'): array
{
    return test()->postJson(route('licensekit.client.login', $product->slug), loaderPayload([
        'login' => $user->email, 'password' => 'password', 'device' => $device,
    ]))->assertOk()->json();
}

test('first key journey starts restricted then grants signed device locked access', function () {
    $user = User::factory()->create();
    $key = LicenseKey::factory()->create();
    $product = $key->batch->plan->product;
    $login = loaderLogin($user, $product);
    expect($login['access'])->toBeFalse()->and($login['code'])->toBe('no_license');
    expect(LoaderSession::sole()->getRawOriginal('token_hash'))->not->toBe($login['token']);
    $this->postJson(route('licensekit.client.heartbeat', $product->slug), loaderPayload(['token' => $login['token'], 'device' => 'pc']))
        ->assertOk()->assertJsonPath('access', false);

    $response = $this->postJson(route('licensekit.client.redeem', $product->slug), loaderPayload(['token' => $login['token'], 'key' => $key->key]))
        ->assertOk()->assertJsonPath('access', true)->assertJsonPath('nonce', str_repeat('a', 64));

    $public = base64_decode(app(ResponseSigning::class)->publicKey());
    $signature = base64_decode($response->headers->get('X-LicenseKit-Signature'));
    expect(sodium_crypto_sign_verify_detached($signature, $response->getContent(), $public))->toBeTrue();
    expect(sodium_crypto_sign_verify_detached($signature, $response->getContent().' ', $public))->toBeFalse();
    expect(License::sole()->device)->toBe('pc');
    expect($key->fresh()->redeemed_at)->not->toBeNull();
});

test('cross product session and key are rejected without consuming key', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $key = LicenseKey::factory()->create();
    $login = loaderLogin($user, $product);

    $this->postJson(route('licensekit.client.redeem', $product->slug), loaderPayload(['token' => $login['token'], 'key' => $key->key]))
        ->assertUnprocessable()->assertJsonPath('error', 'invalid_key');
    $this->postJson(route('licensekit.client.heartbeat', $key->batch->plan->product->slug), loaderPayload(['token' => $login['token'], 'device' => 'pc']))
        ->assertUnauthorized();
    expect($key->fresh()->redeemed_at)->toBeNull();
});

test('password changes invalidate loader sessions', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $login = loaderLogin($user, $product);
    $user->update(['password' => Hash::make('new-password')]);

    $this->postJson(route('licensekit.client.heartbeat', $product->slug), loaderPayload(['token' => $login['token'], 'device' => 'pc']))
        ->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});

test('move invalidates old sessions and shares website cooldown', function () {
    $user = User::factory()->create();
    $license = License::factory()->create(['user_id' => $user->id]);
    $product = $license->product;
    $old = loaderLogin($user, $product);
    $this->postJson(route('licensekit.client.login', $product->slug), loaderPayload(['login' => $user->email, 'password' => 'password', 'device' => 'new']))
        ->assertForbidden()->assertJsonPath('error', 'device_mismatch');

    $this->postJson(route('licensekit.client.move-device', $product->slug), loaderPayload(['login' => $user->email, 'password' => 'password', 'device' => 'new']))
        ->assertOk()->assertJsonPath('access', true);

    expect($license->fresh()->device)->toBe('new');
    $this->postJson(route('licensekit.client.heartbeat', $product->slug), loaderPayload(['token' => $old['token'], 'device' => 'pc']))->assertUnauthorized();
    expect(app(LicensedDevices::class)->eligibility($license->fresh())['allowed'])->toBeFalse();
});

test('bans apply with device lock off and do not suspend unselected shared accounts', function () {
    $user = User::factory()->create();
    $license = License::factory()->create(['user_id' => $user->id, 'device' => 'pc']);
    $other = License::factory()->create(['user_id' => User::factory()->create()->id, 'product_id' => $license->product_id, 'device' => 'pc']);
    $license->product->update(['device_lock' => false]);
    app(DeviceBans::class)->ban($license, $user, suspend: false);

    $this->postJson(route('licensekit.client.login', $license->product->slug), loaderPayload(['login' => $user->email, 'password' => 'password', 'device' => 'pc']))
        ->assertForbidden()->assertJsonPath('error', 'device_banned');
    expect($other->fresh()->status)->toBe('active');
});

test('errors and throttles are signed and missing signing key fails closed', function () {
    $product = Product::factory()->create();
    $url = route('licensekit.client.login', $product->slug);
    $this->postJson($url, [])->assertUnprocessable()->assertHeader('X-LicenseKit-Signature');
    foreach (range(1, 6) as $attempt) {
        $response = $this->postJson($url, loaderPayload(['login' => 'missing@example.test', 'password' => 'wrong', 'device' => 'pc']));
    }
    $response->assertTooManyRequests()->assertHeader('X-LicenseKit-Signature');
    config(['licensekit.signing_key_path' => storage_path('no-such-key')]);
    $this->postJson($url, loaderPayload())->assertServiceUnavailable();
});

test('pause terminates sessions and lazy resume restores saved time', function () {
    $user = User::factory()->create();
    $license = License::factory()->create(['user_id' => $user->id]);
    loaderLogin($user, $license->product);
    app(ManageLicenses::class)->pause($license, $user, until: CarbonImmutable::now()->addHour());
    $this->assertDatabaseCount('licensekit_loader_sessions', 0);
    $this->travel(2)->hours();

    expect(loaderLogin($user, $license->product)['access'])->toBeTrue();
    expect($license->fresh()->status)->toBe('active');
});

test('malformed login and token fields return signed validation failures', function () {
    $product = Product::factory()->create();
    $this->postJson(route('licensekit.client.login', $product->slug), loaderPayload(['login' => ['bad'], 'password' => 'password', 'device' => 'pc']))
        ->assertUnprocessable()->assertHeader('X-LicenseKit-Signature');
    $this->postJson(route('licensekit.client.heartbeat', $product->slug), loaderPayload(['token' => ['bad'], 'device' => 'pc']))
        ->assertUnprocessable()->assertHeader('X-LicenseKit-Signature');
});

test('free window creates license without device lock and ends exactly on deadline', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $product = Product::factory()->create(['device_lock' => false, 'free_from' => now(), 'free_until' => now()->addHour()]);
    $login = loaderLogin($user, $product);
    expect($login['access'])->toBeTrue();
    expect(License::sole()->device)->toBeNull();
    $this->travel(1)->hours();
    expect(loaderLogin($user, $product)['access'])->toBeFalse();
});

test('expired license moves to new PC without granting access', function () {
    $user = User::factory()->create();
    $license = License::factory()->expired()->create(['user_id' => $user->id, 'device' => 'old']);
    $this->postJson(route('licensekit.client.move-device', $license->product->slug), loaderPayload(['login' => $user->email, 'password' => 'password', 'device' => 'new']))
        ->assertOk()->assertJsonPath('access', false)->assertJsonPath('code', 'expired');
    expect($license->fresh()->device)->toBe('new');
});

test('product bans stay scoped and global bans cover other products', function () {
    $user = User::factory()->create();
    $first = License::factory()->create(['user_id' => $user->id, 'device' => 'pc']);
    $second = License::factory()->create(['user_id' => $user->id, 'device' => 'pc']);
    $bans = app(DeviceBans::class);
    $ban = $bans->ban($first, $user, suspend: false);
    expect(loaderLogin($user, $second->product)['access'])->toBeTrue();
    $bans->unban($ban, $user);
    $bans->ban($first, $user, allProducts: true, suspend: false);
    $this->postJson(route('licensekit.client.login', $second->product->slug), loaderPayload(['login' => $user->email, 'password' => 'password', 'device' => 'pc']))
        ->assertForbidden()->assertJsonPath('error', 'device_banned');
});
