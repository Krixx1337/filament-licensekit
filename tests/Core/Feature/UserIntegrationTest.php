<?php

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Schema;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Core\Users\ConfiguredUserModel;
use LicenseKit\Tests\Core\Fixtures\ClientSecurity;
use LicenseKit\Tests\Core\Fixtures\ClientSessions;
use LicenseKit\Tests\Core\Fixtures\CustomUser;
use LicenseKit\Tests\Core\Fixtures\HostAuthenticateUser;
use LicenseKit\Tests\Core\Fixtures\User;

class HostPasswordProvider extends EloquentUserProvider
{
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        return $credentials['password'] === 'host-secret';
    }
}

test('desktop credentials use native host provider without browser authentication or remember changes', function () {
    $user = User::factory()->create();
    $this->app['auth']->provider('host-password', fn ($app, array $config) => new HostPasswordProvider($app['hash'], $config['model']));
    config(['auth.providers.members' => ['driver' => 'host-password', 'model' => User::class],
        'auth.guards.customer' => ['driver' => 'session', 'provider' => 'members'], 'licensekit.user.guard' => 'customer']);
    expect(app(HostAuthenticateUser::class)->authenticate('  '.strtoupper($user->email).'  ', 'host-secret')->getKey())->toBe($user->id);
    expect(app(HostAuthenticateUser::class)->authenticate($user->email, 'password'))->toBeNull();
    $this->assertGuest('customer');
    expect($user->fresh()->getRememberToken())->toBe('');
});

test('missing desktop users return invalid credentials under host argon hasher', function () {
    config(['hashing.driver' => 'argon']);
    $product = Product::factory()->create();
    $this->postJson(route('licensekit.desktop.login', $product->slug), [
        'login' => 'missing@example.com', 'password' => 'password',
        'hwid' => ['value' => 'pc'],
    ])->assertUnauthorized()->assertJsonPath('error', 'invalid_credentials');
    $this->assertDatabaseCount('client_sessions', 0);
});

test('configured username credentials use host provider', function () {
    Schema::table('users', fn ($table) => $table->string('username')->nullable());
    config(['host_client.username_column' => 'username']);
    $user = User::factory()->create(['username' => 'player']);
    expect(app(HostAuthenticateUser::class)->authenticate(' PLAYER ', 'password')->getKey())->toBe($user->id);
    expect(app(HostAuthenticateUser::class)->authenticate('player', 'wrong'))->toBeNull();
});

test('custom guard provider and user table own claimed license', function () {
    config(['auth.providers.members' => ['driver' => 'eloquent', 'model' => CustomUser::class],
        'auth.guards.customer' => ['driver' => 'session', 'provider' => 'members'], 'licensekit.user.guard' => 'customer']);
    $this->app['db']->connection()->rollBack();
    $this->artisan('migrate:fresh')->assertSuccessful();
    $this->app['db']->connection()->beginTransaction();
    $user = CustomUser::factory()->create();
    $plan = LicensePlan::factory()->create();
    $key = app(CreateBatch::class)->create($plan, 1, 'sale', 'test')->keys->first();
    $entitlement = app(ClaimLicense::class)->claim($user, $key->key);
    expect($entitlement->user)->toBeInstanceOf(CustomUser::class);
    expect($entitlement->user_id)->toBe($user->id);
    expect(Schema::hasTable('members'))->toBeTrue();
    expect(app(HostAuthenticateUser::class)->authenticate($user->email, 'password'))->toBeInstanceOf(CustomUser::class);
    $this->artisan('licensekit:check')->assertSuccessful();
});

test('unsupported host user configuration fails clearly', function (array $settings) {
    config($settings);
    expect(fn () => ConfiguredUserModel::className())->toThrow(InvalidArgumentException::class);
    $this->artisan('licensekit:check')->assertFailed();
})->with([
    'missing guard' => [['licensekit.user.guard' => 'missing']],
    'missing provider' => [['auth.guards.web.provider' => 'missing']],
    'non eloquent' => [['auth.providers.users.model' => stdClass::class]],
]);

test('installation check reports configured lookup columns without changing users', function () {
    config(['licensekit.user.display_column' => 'missing_email']);
    $this->artisan('licensekit:check')->assertFailed();
    expect(Schema::hasColumn('users', 'missing_email'))->toBeFalse();
    $this->assertDatabaseCount('users', 0);
});

test('native provider returning wrong host model fails closed', function () {
    $user = User::factory()->create();
    $this->app['auth']->provider('mismatched', fn ($app, array $config) => new HostPasswordProvider($app['hash'], User::class));
    config(['auth.providers.users' => ['driver' => 'mismatched', 'model' => CustomUser::class]]);
    expect(fn () => app(HostAuthenticateUser::class)->authenticate($user->email, 'host-secret'))
        ->toThrow(InvalidArgumentException::class, 'unsupported LicenseKit user model');
    $this->assertDatabaseCount('client_sessions', 0);
});

test('unsupported native provider driver reports installation failure', function () {
    config(['auth.providers.users.driver' => 'missing-driver']);
    $this->artisan('licensekit:check')->assertFailed();
    $this->assertDatabaseCount('users', 0);
});

test('display configuration does not control host credentials or security invalidation', function (string $field, string $value, bool $bulk) {
    Schema::table('users', fn ($table) => $table->string('username')->nullable());
    $user = User::factory()->create(['username' => 'player']);
    $product = Product::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $sessions = app(ClientSessions::class);
    $token = $sessions->login($product, $user->email, 'password', ['value' => 'pc'])['token'];
    $fingerprint = app(ClientSecurity::class)->fingerprint($user);
    config(['licensekit.user.display_column' => 'username']);

    expect(app(HostAuthenticateUser::class)->authenticate($user->email, 'password')->getKey())->toBe($user->id);
    expect(app(ClientSecurity::class)->fingerprint($user))->toBe($fingerprint);
    $this->withToken($token)->postJson(route('licensekit.desktop.heartbeat', $product->slug), desktopRequest($user, 'pc'))->assertOk();
    if ($bulk) {
        User::whereKey($user->getKey())->update([$field => $value]);
    } else {
        $user->update([$field => $value]);
    }
    $this->withToken($token)->postJson(route('licensekit.desktop.heartbeat', $product->slug), desktopRequest($user, 'pc'))->assertUnauthorized();
    $this->assertDatabaseCount('client_sessions', $bulk ? 1 : 0);
    expect($entitlement->fresh()->user_id)->toBe($user->id);
    $this->assertDatabaseHas('licensekit_device_bindings', ['user_id' => $user->id, 'scope_key' => 'product:'.$product->id]);
})->with([
    'email observer' => ['email', 'changed@example.com', false],
    'username observer' => ['username', 'renamed-player', false],
    'email bulk update' => ['email', 'changed@example.com', true],
    'username bulk update' => ['username', 'renamed-player', true],
]);

test('username-only host passes installation checks without email verification', function () {
    config(['licensekit.user.display_column' => 'username', 'licensekit.user.require_verified' => false]);
    Schema::table('users', function ($table) {
        $table->renameColumn('email', 'username');
        $table->dropColumn('email_verified_at');
    });
    $this->artisan('licensekit:check')->assertSuccessful();
});
