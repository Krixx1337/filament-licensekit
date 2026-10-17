<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use LicenseKit\Core\Actions\DeviceBans;
use LicenseKit\Core\Actions\LicensedDevices;
use LicenseKit\Core\Actions\ManageLicenses;
use LicenseKit\Core\Events\AccessRevoked;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LoaderSession;
use LicenseKit\Core\Models\Product;
use LicenseKit\Tests\Core\Fixtures\CustomProtocolController;
use LicenseKit\Tests\Core\Fixtures\User;

// A seller's own protocol must work without forking the package: public services only.
beforeEach(function () {
    Route::middleware('api')->prefix('my-app/{product:slug}')->group(function (): void {
        Route::post('login', [CustomProtocolController::class, 'login']);
        Route::post('heartbeat', [CustomProtocolController::class, 'heartbeat']);
        Route::post('redeem', [CustomProtocolController::class, 'redeem']);
    });
    Event::listen(AccessRevoked::class, [CustomProtocolController::class, 'revoke']);
});

function customLicense(array $product = []): License
{
    return License::factory()->create(['user_id' => User::factory()->create()->id, 'product_id' => Product::factory()->create($product)->id]);
}

function customLogin(License $license, string $hwid = 'pc-a', array $extra = []): TestResponse
{
    return test()->withHeader('X-Nonce', 'n1')->postJson('/my-app/'.$license->product->slug.'/login',
        ['username' => $license->user->email, 'password' => 'password', 'hwid' => $hwid, ...$extra]);
}

function customHeartbeat(License $license, string $token): TestResponse
{
    return test()->withToken($token)->withHeader('X-Nonce', 'n2')->postJson('/my-app/'.$license->product->slug.'/heartbeat');
}

test('a custom protocol locks the PC, heartbeats and signs its own way', function () {
    $license = customLicense();
    $response = customLogin($license)->assertOk();

    $proof = hash_hmac('sha256', "MYAPP\nn1\n200\n".$response->getContent(), CustomProtocolController::SECRET);
    expect($response->headers->get('X-Proof'))->toBe($proof)
        ->and($response->json('until'))->toBe($license->expires_at->getTimestamp())
        ->and($license->fresh()->device)->toBe('pc-a')
        ->and(LoaderSession::count())->toBe(0);
    customHeartbeat($license, $response->json('token'))->assertOk();
    customLogin($license, 'pc-b')->assertForbidden()->assertJsonPath('error', 'device_mismatch');
    $this->withHeader('X-Nonce', 'n1')->postJson('/my-app/'.$license->product->slug.'/login', ['username' => $license->user->email, 'password' => 'wrong', 'hwid' => 'pc-a'])
        ->assertUnauthorized()->assertJsonPath('error', 'bad_login');
});

test('every heartbeat reruns the server checks', function (Closure $change, string $error) {
    $license = customLicense();
    $token = customLogin($license)->json('token');

    $change($license);

    customHeartbeat($license, $token)->assertJsonPath('error', $error);
})->with([
    'suspended' => [fn (License $license) => app(ManageLicenses::class)->suspend($license, testAdminActor()), 'session_ended'],
    'expired' => [fn (License $license) => $license->update(['expires_at' => now()->subMinute()]), 'expired'],
    'account suspended' => [fn (License $license) => $license->user->update(['is_suspended' => true]), 'user_unavailable'],
    'maintenance' => [fn (License $license) => $license->product->update(['status' => Product::MAINTENANCE]), 'maintenance'],
    'PC banned' => [fn (License $license) => app(DeviceBans::class)->ban($license->fresh(), testAdminActor(), suspend: false), 'device_banned'],
    'staff reset' => [fn (License $license) => app(LicensedDevices::class)->adminReset($license->user, $license->product, testAdminActor()), 'session_ended'],
]);

test('a custom protocol moves PCs with the product cooldown', function () {
    $license = customLicense(['device_move_cooldown_days' => 7]);
    $old = customLogin($license)->json('token');

    customLogin($license, 'pc-b', ['move' => true])->assertOk();
    customHeartbeat($license, $old)->assertUnauthorized();
    expect($license->fresh()->device)->toBe('pc-b');
    customLogin($license, 'pc-c', ['move' => true])->assertStatus(429)->assertJsonPath('error', 'move_cooldown');
});

test('a custom protocol redeems keys into the account', function () {
    $user = User::factory()->create();
    $key = LicenseKey::factory()->create();
    $product = $key->batch->plan->product;
    $license = License::factory()->expired()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    customLogin($license)->assertForbidden()->assertJsonPath('error', 'expired');
    $license->update(['expires_at' => now()->addMinute()]);
    $token = customLogin($license)->json('token');

    $this->withToken($token)->withHeader('X-Nonce', 'n3')->postJson('/my-app/'.$product->slug.'/redeem', ['key' => $key->key])->assertOk();

    expect($key->fresh()->redeemed_at)->not->toBeNull()->and($license->fresh()->expires_at->isAfter(now()->addDays(2)))->toBeTrue();
});
