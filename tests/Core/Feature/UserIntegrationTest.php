<?php

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Schema;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\LoaderSessions;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\Product;
use LicenseKit\Core\Users\ConfiguredUserModel;
use LicenseKit\Tests\Core\Fixtures\CustomUser;
use LicenseKit\Tests\Core\Fixtures\User;

class HostPasswordProvider extends EloquentUserProvider
{
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        return $credentials['password'] === 'host-secret';
    }
}

test('loader uses native host provider without changing browser session or remember token', function () {
    $user = User::factory()->create();
    $this->app['auth']->provider('host-password', fn ($app, array $config) => new HostPasswordProvider($app['hash'], $config['model']));
    config(['auth.providers.members' => ['driver' => 'host-password', 'model' => User::class],
        'auth.guards.customer' => ['driver' => 'session', 'provider' => 'members'], 'licensekit.user_guard' => 'customer']);
    $product = Product::factory()->create();
    $result = app(LoaderSessions::class)->login($product, $user->email, 'host-secret', 'pc');
    expect($result['access'])->toBeFalse();
    expect(fn () => app(LoaderSessions::class)->login($product, $user->email, 'wrong', 'pc'))->toThrow(LicensingException::class, 'invalid credentials');
    $this->assertGuest('customer');
    expect($user->fresh()->getRememberToken())->toBe('');
});

test('username login is independent of admin display column', function () {
    Schema::table('users', fn ($table) => $table->string('username')->nullable());
    config(['licensekit.client_login_column' => 'username']);
    $user = User::factory()->create(['username' => 'player']);
    $product = Product::factory()->create();
    expect(app(LoaderSessions::class)->login($product, 'player', 'password', 'pc')['access'])->toBeFalse();
    expect(fn () => app(LoaderSessions::class)->login($product, $user->email, 'password', 'pc'))->toThrow(LicensingException::class);
});

test('custom guard provider and user table own redeemed license', function () {
    config(['auth.providers.members' => ['driver' => 'eloquent', 'model' => CustomUser::class],
        'auth.guards.customer' => ['driver' => 'session', 'provider' => 'members'], 'licensekit.user_guard' => 'customer']);
    $this->app['db']->connection()->rollBack();
    $this->artisan('migrate:fresh')->assertSuccessful();
    $this->app['db']->connection()->beginTransaction();
    $user = CustomUser::factory()->create();
    $key = LicenseKey::factory()->create();
    $license = app(ClaimLicense::class)->claim($user, $key->key);
    expect($license->user)->toBeInstanceOf(CustomUser::class);
    expect(app(LoaderSessions::class)->login($license->product, $user->email, 'password', 'pc')['access'])->toBeTrue();
    $this->artisan('licensekit:check')->assertSuccessful();
});

test('unsupported host user configurations fail diagnostics', function (array $settings) {
    config($settings);
    expect(fn () => ConfiguredUserModel::className())->toThrow(InvalidArgumentException::class);
    $this->artisan('licensekit:check')->assertFailed();
})->with([
    [['licensekit.user_guard' => 'missing']], [['auth.guards.web.provider' => 'missing']], [['auth.providers.users.model' => stdClass::class]],
]);

test('verification follows host model and is never controlled by old config', function () {
    $user = User::factory()->create(['email_verified_at' => null]);
    config(['licensekit.user.require_verified' => false]);
    $key = LicenseKey::factory()->create();
    expect(fn () => app(ClaimLicense::class)->claim($user, $key->key))->toThrow(LicensingException::class, 'email not verified');
    expect(fn () => app(LoaderSessions::class)->login($key->batch->plan->product, $user->email, 'password', 'pc'))->toThrow(LicensingException::class, 'email not verified');
    expect($key->fresh()->redeemed_at)->toBeNull();
});
