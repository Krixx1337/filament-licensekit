<?php

use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use LicenseKit\Core\Actions\DeviceBans;
use LicenseKit\Core\Actions\LicensedDevices;
use LicenseKit\Core\Actions\SignInCheck;
use LicenseKit\Core\Actions\SignInProblems;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\ActivityEntry;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LoaderSession;
use LicenseKit\Core\Models\Product;
use LicenseKit\Tests\Core\Fixtures\User;

function problemLogin(License $license, string $device = 'pc-a', string $password = 'password'): TestResponse
{
    return test()->postJson(route('licensekit.client.login', $license->product->slug), [
        'nonce' => str_repeat('a', 64), 'login' => $license->user->email, 'password' => $password, 'device' => $device,
    ]);
}

function problems(): Collection
{
    return ActivityEntry::where('event', SignInProblems::EVENT)->orderBy('id')->get()->map(fn (ActivityEntry $entry): string => $entry->meta['code']);
}

test('a refused app sign-in is logged even though its transaction rolls back', function () {
    $license = License::factory()->create(['user_id' => User::factory()->create(['email' => 'pat@example.com'])->id, 'device' => 'pc-a']);

    problemLogin($license, 'pc-b')->assertForbidden();

    $entry = ActivityEntry::where('event', SignInProblems::EVENT)->sole();
    expect($entry->meta)->toMatchArray(['code' => 'device_mismatch', 'device' => 'pc-b', 'product_id' => $license->product_id, 'license_id' => $license->id])
        ->and($entry->summary())->toBe("pat@example.com couldn't sign in (locked to another PC)");
});

test('wrong passwords are logged for known accounts only', function () {
    $license = License::factory()->create(['user_id' => User::factory()->create()->id]);

    problemLogin($license, password: 'wrong')->assertUnauthorized();
    $this->postJson(route('licensekit.client.login', $license->product->slug), ['nonce' => str_repeat('a', 64), 'login' => 'nobody@example.com', 'password' => 'x', 'device' => 'pc'])
        ->assertUnauthorized();

    expect(problems()->all())->toBe(['invalid_credentials']);
});

test('the same problem is logged once per quiet period', function () {
    $license = License::factory()->create(['user_id' => User::factory()->create()->id, 'device' => 'pc-a']);

    problemLogin($license, 'pc-b');
    problemLogin($license, 'pc-b');
    problemLogin($license, password: 'wrong');
    $this->travel(SignInProblems::QUIET_MINUTES + 1)->minutes();
    problemLogin($license, 'pc-b');

    expect(problems()->all())->toBe(['device_mismatch', 'invalid_credentials', 'device_mismatch']);
});

test('restricted sign-ins without a license are logged against the customer', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();

    $this->postJson(route('licensekit.client.login', $product->slug), ['nonce' => str_repeat('a', 64), 'login' => $user->email, 'password' => 'password', 'device' => 'pc'])
        ->assertOk()->assertJsonPath('code', 'no_license');

    $entry = ActivityEntry::where('event', SignInProblems::EVENT)->sole();
    expect($entry->subject_id)->toBe((string) $user->id)->and($entry->meta['product_id'])->toBe($product->id);
});

test('heartbeat refusals are logged, ended sessions are not', function () {
    $license = License::factory()->create(['user_id' => User::factory()->create()->id]);
    $token = problemLogin($license)->json('token');
    $heartbeat = fn (string $token, string $device) => $this->postJson(route('licensekit.client.heartbeat', $license->product->slug),
        ['nonce' => str_repeat('a', 64), 'token' => $token, 'device' => $device]);

    $heartbeat($token, 'pc-b')->assertForbidden();
    LoaderSession::query()->update(['expires_at' => now()->subMinute()]);
    $heartbeat($token, 'pc-a')->assertUnauthorized()->assertJsonPath('error', 'invalid_token');

    expect(problems()->all())->toBe(['device_mismatch']);
});

test('custom protocols get the log through LicensedDevices', function () {
    $license = License::factory()->create(['user_id' => User::factory()->create()->id, 'device' => 'pc-a']);
    app(DeviceBans::class)->ban($license, testAdminActor(), suspend: false);

    expect(fn () => app(LicensedDevices::class)->verify($license->user, $license->product, 'pc-a'))->toThrow(LicensingException::class);
    expect(problems()->all())->toBe(['device_banned']);
});

test('the sign-in check reports what the app would say without writing anything', function () {
    $license = License::factory()->create(['user_id' => User::factory()->create()->id, 'device' => 'pc-a']);
    $check = app(SignInCheck::class);

    expect($check->forLicense($license)->validUntil->getTimestamp())->toBe($license->expires_at->getTimestamp());
    expect(fn () => $check->check($license->user, $license->product, 'pc-b'))->toThrow(LicensingException::class, 'device mismatch');
    $license->update(['expires_at' => now()->subDay()]);
    expect(fn () => $check->forLicense($license->fresh()))->toThrow(LicensingException::class, 'expired');
    expect(ActivityEntry::count())->toBe(0);
});
