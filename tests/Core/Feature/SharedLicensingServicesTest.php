<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LicenseKit\Core\Actions\AccessPolicy;
use LicenseKit\Core\Actions\LicensedDevices;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\DeviceBinding;
use LicenseKit\Core\Models\Entitlement;
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
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id]);
    $hwid = ['value' => 'pc'];

    expect(app(AccessPolicy::class)->authorize($user, $entitlement->product)->source)->toBe('paid');
    $context = app(LicensedDevices::class)->bind($user, $entitlement->product, $hwid);
    expect(app(LicensedDevices::class)->verify($user, $entitlement->product, $hwid)->hwid)->toBe($context->hwid);
    expect(Route::has('licensekit.desktop.login'))->toBeFalse();
    expect(Route::has('licensekit.desktop.heartbeat'))->toBeFalse();
    expect(Schema::hasTable('licensekit_desktop_sessions'))->toBeFalse();
    expect(Schema::hasTable('client_sessions'))->toBeFalse();
    expect(config('licensekit.desktop'))->toBeNull();
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->artisan('licensekit:check')->assertSuccessful();
});

test('device verification never creates or changes binding including reset history', function () {
    $user = User::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id]);
    $hwid = ['value' => 'pc'];
    expect(fn () => app(LicensedDevices::class)->verify($user, $entitlement->product, $hwid))->toThrow(LicensingException::class, 'device not bound');
    $this->assertDatabaseCount('licensekit_device_bindings', 0);
    $binding = DeviceBinding::factory()->create(['user_id' => $user->id, 'scope_key' => 'product:'.$entitlement->product_id, 'hwid' => null, 'bound_at' => null, 'last_hwid_reset_at' => now()]);
    $before = $binding->fresh()->getAttributes();
    expect(fn () => app(LicensedDevices::class)->verify($user, $entitlement->product, $hwid))->toThrow(LicensingException::class);
    expect($binding->fresh()->getAttributes())->toBe($before);
});

test('denied binding has neutral reason without protocol response or credential fields', function () {
    $user = User::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id, 'status' => 'revoked']);
    try {
        app(LicensedDevices::class)->bind($user, $entitlement->product, ['value' => 'pc']);
        $this->fail('Revoked access must deny binding.');
    } catch (LicensingException $exception) {
        expect($exception->error)->toBe('license_unavailable');
        expect($exception->status)->toBe(403);
        expect(method_exists($exception, 'render'))->toBeFalse();
    }
    $this->assertDatabaseCount('licensekit_device_bindings', 0);
});

test('standalone binding refreshes stale user and product state', function () {
    $user = User::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id]);
    $product = $entitlement->product;
    Product::whereKey($product->id)->update(['status' => 'maintenance']);

    expect(fn () => app(LicensedDevices::class)->bind($user, $product, ['value' => 'pc']))->toThrow(LicensingException::class, 'service unavailable');
    $this->assertDatabaseCount('licensekit_device_bindings', 0);
});

test('host database failure rolls back explicit binding inside licensing transaction', function () {
    $user = User::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id]);

    expect(fn () => DB::transaction(function () use ($user, $entitlement): void {
        app(LicensedDevices::class)->bind($user, $entitlement->product, ['value' => 'pc']);
        DB::table('users')->where('id', $user->id)->update(['email' => 'changed@example.test']);
        throw new RuntimeException('host write failed');
    }))->toThrow(RuntimeException::class, 'host write failed');
    $this->assertDatabaseCount('licensekit_device_bindings', 0);
    expect($user->fresh()->email)->toBe($user->email);
});

test('client binding rejects unsupported user models even when their IDs collide', function () {
    $user = User::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id]);
    $other = new class extends Model
    {
        protected $table = 'users';
    };
    $other->setRawAttributes($user->getAttributes(), true);
    $other->exists = true;

    expect(fn () => app(LicensedDevices::class)->bind($other, $entitlement->product, ['value' => 'pc']))->toThrow(LicensingException::class, 'user unavailable');
    $this->assertDatabaseCount('licensekit_device_bindings', 0);
});

test('minimal licensing host does not require a password column', function () {
    $user = User::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id]);
    Schema::table('users', fn ($table) => $table->dropColumn('password'));

    expect(app(AccessPolicy::class)->authorize($user, $entitlement->product)->source)->toBe('paid');
    $this->artisan('licensekit:check')->assertSuccessful();
});

test('host can choose its own HTTP mapping for neutral device denials', function () {
    $user = User::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id]);
    Route::get('host/device-check', function () use ($user, $entitlement) {
        try {
            app(LicensedDevices::class)->verify($user, $entitlement->product, ['value' => 'pc']);
        } catch (LicensingException $exception) {
            return response()->json(['host_reason' => $exception->error], 412);
        }

        return response()->noContent();
    });

    $this->getJson('host/device-check')->assertStatus(412)->assertExactJson(['host_reason' => 'device_not_bound']);
    $this->assertDatabaseCount('licensekit_device_bindings', 0);
});

test('custom host catalog operations validate and audit the actor without a panel', function () {
    $admin = User::factory()->create();
    expect(fn () => CatalogWrites::create(Product::class, ['name' => '', 'slug' => 'invalid'], $admin))->toThrow(ValidationException::class);
    $product = CatalogWrites::create(Product::class, ['name' => 'Custom host', 'slug' => 'custom-host'], $admin);
    $this->assertDatabaseHas('licensekit_audit_events', ['actor' => 'user:'.$admin->id, 'event' => 'configuration_created', 'subject_id' => (string) $product->id]);
    CatalogWrites::update($product, ['name' => 'Renamed'], $admin);
    expect($product->fresh()->name)->toBe('Renamed');
});
