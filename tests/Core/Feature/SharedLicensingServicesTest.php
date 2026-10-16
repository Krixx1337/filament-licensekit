<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LicenseKit\Core\Actions\AccessPolicy;
use LicenseKit\Core\Actions\LicensedDevices;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\CatalogWrites;
use LicenseKit\Tests\Core\Fixtures\User;

function bootMinimalLicensingHost(): void
{
    test()->clientFixture = false;
    test()->refreshApplication();
    app('migrator')->path(__DIR__.'/../Fixtures/migrations');
    app('migrator')->path(__DIR__.'/../../../vendor/laravel/sanctum/database/migrations');
    test()->artisan('migrate', ['--force' => true])->assertSuccessful();
}

beforeEach(fn () => bootMinimalLicensingHost());

test('minimal host uses licensing services without client routes or session storage', function () {
    $user = User::factory()->create();
    $license = License::factory()->create(['user_id' => $user->id]);
    $hwid = 'pc';

    expect(app(AccessPolicy::class)->authorize($user, $license->product)->source)->toBe('paid');
    $context = app(LicensedDevices::class)->bind($user, $license->product, $hwid);
    expect(app(LicensedDevices::class)->verify($user, $license->product, $hwid)->device)->toBe($context->device);
    expect(Route::has('licensekit.desktop.login'))->toBeFalse();
    expect(Route::has('licensekit.desktop.heartbeat'))->toBeFalse();
    expect(Schema::hasTable('licensekit_desktop_sessions'))->toBeFalse();
    expect(Schema::hasTable('client_sessions'))->toBeFalse();
    expect(config('licensekit.desktop'))->toBeNull();
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->artisan('licensekit:check')->assertSuccessful();
});

test('device verification never saves a device', function () {
    $user = User::factory()->create();
    $license = License::factory()->create(['user_id' => $user->id]);
    $hwid = 'pc';
    expect(fn () => app(LicensedDevices::class)->verify($user, $license->product, $hwid))->toThrow(LicensingException::class, 'device not bound');
    expect(lockedDeviceCount())->toBe(0);
    $license->update(['device_reset_at' => now()]);
    $before = $license->fresh()->getAttributes();
    expect(fn () => app(LicensedDevices::class)->verify($user, $license->product, $hwid))->toThrow(LicensingException::class);
    expect($license->fresh()->getAttributes())->toBe($before);
});

test('denied binding has neutral reason without protocol response or credential fields', function () {
    $user = User::factory()->create();
    $license = License::factory()->create(['user_id' => $user->id, 'status' => 'revoked']);
    try {
        app(LicensedDevices::class)->bind($user, $license->product, 'pc');
        $this->fail('Revoked access must deny binding.');
    } catch (LicensingException $exception) {
        expect($exception->error)->toBe('license_unavailable');
        expect($exception->status)->toBe(403);
        expect(method_exists($exception, 'render'))->toBeFalse();
    }
    expect(lockedDeviceCount())->toBe(0);
});

test('standalone binding refreshes stale user and product state', function () {
    $user = User::factory()->create();
    $license = License::factory()->create(['user_id' => $user->id]);
    $product = $license->product;
    Product::whereKey($product->id)->update(['status' => 'maintenance']);

    expect(fn () => app(LicensedDevices::class)->bind($user, $product, 'pc'))->toThrow(LicensingException::class, 'service unavailable');
    expect(lockedDeviceCount())->toBe(0);
});

test('host database failure rolls back explicit binding inside licensing transaction', function () {
    $user = User::factory()->create();
    $license = License::factory()->create(['user_id' => $user->id]);

    expect(fn () => DB::transaction(function () use ($user, $license): void {
        app(LicensedDevices::class)->bind($user, $license->product, 'pc');
        DB::table('users')->where('id', $user->id)->update(['email' => 'changed@example.test']);
        throw new RuntimeException('host write failed');
    }))->toThrow(RuntimeException::class, 'host write failed');
    expect(lockedDeviceCount())->toBe(0);
    expect($user->fresh()->email)->toBe($user->email);
});

test('client binding rejects unsupported user models even when their IDs collide', function () {
    $user = User::factory()->create();
    $license = License::factory()->create(['user_id' => $user->id]);
    $other = new class extends Model
    {
        protected $table = 'users';
    };
    $other->setRawAttributes($user->getAttributes(), true);
    $other->exists = true;

    expect(fn () => app(LicensedDevices::class)->bind($other, $license->product, 'pc'))->toThrow(LicensingException::class, 'user unavailable');
    expect(lockedDeviceCount())->toBe(0);
});

test('minimal licensing host does not require a password column', function () {
    $user = User::factory()->create();
    $license = License::factory()->create(['user_id' => $user->id]);
    Schema::table('users', fn ($table) => $table->dropColumn('password'));

    expect(app(AccessPolicy::class)->authorize($user, $license->product)->source)->toBe('paid');
    $this->artisan('licensekit:check')->assertSuccessful();
});

test('host can choose its own HTTP mapping for neutral device denials', function () {
    $user = User::factory()->create();
    $license = License::factory()->create(['user_id' => $user->id]);
    Route::get('host/device-check', function () use ($user, $license) {
        try {
            app(LicensedDevices::class)->verify($user, $license->product, 'pc');
        } catch (LicensingException $exception) {
            return response()->json(['host_reason' => $exception->error], 412);
        }

        return response()->noContent();
    });

    $this->getJson('host/device-check')->assertStatus(412)->assertExactJson(['host_reason' => 'device_not_bound']);
    expect(lockedDeviceCount())->toBe(0);
});

test('custom host catalog operations validate and audit the actor without a panel', function () {
    $admin = User::factory()->create();
    expect(fn () => CatalogWrites::create(Product::class, ['name' => '', 'slug' => 'invalid'], $admin))->toThrow(ValidationException::class);
    $product = CatalogWrites::create(Product::class, ['name' => 'Custom host', 'slug' => 'custom-host'], $admin);
    $this->assertDatabaseHas('licensekit_audit_events', ['actor' => 'user:'.$admin->id, 'event' => 'configuration_created', 'subject_id' => (string) $product->id]);
    CatalogWrites::update($product, ['name' => 'Renamed'], $admin);
    expect($product->fresh()->name)->toBe('Renamed');
});
