<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use LicenseKit\Core\Actions\DeviceBindings;
use LicenseKit\Core\Actions\LicensedDevices;
use LicenseKit\Core\Data\DeviceAuthorization;
use LicenseKit\Core\Events\ProductAccessInvalidated;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\AuditEvent;
use LicenseKit\Core\Models\DeviceBinding;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\Product;
use LicenseKit\Tests\Core\Fixtures\HostDevicePolicy;
use LicenseKit\Tests\Core\Fixtures\User;

dataset('device operations', ['bind', 'verify', 'licensed-bind', 'licensed-verify', 'upgrade']);

function exemptDeviceOperation(string $operation, User $user, Product $product, array $hwid): ?DeviceAuthorization
{
    if ($operation === 'bind' || $operation === 'verify') {
        app(DeviceBindings::class)->{$operation}($user, $product, $hwid);

        return null;
    }

    return match ($operation) {
        'licensed-bind' => app(LicensedDevices::class)->bind($user, $product, $hwid),
        'licensed-verify' => app(LicensedDevices::class)->verify($user, $product, $hwid),
        'upgrade' => app(LicensedDevices::class)->upgrade($user, $product, $hwid),
    };
}

function grantHwidExemption(string $source, User $user, Product $product): void
{
    if ($source === 'product') {
        $product->update(['requires_hwid' => false]);
    } else {
        Gate::define('bypassHwid', fn (User $actor, Product $subject): bool => $actor->is($user) && $subject->is($product));
    }
}

test('initial schema can roll back and reinstall with HWID protection enabled by default', function () {
    $migration = require __DIR__.'/../../../database/migrations/core/2026_10_12_000001_create_licensekit_core_tables.php';
    $migration->down();
    expect(Schema::hasTable('licensekit_products'))->toBeFalse();

    $migration->up();
    $id = DB::table('licensekit_products')->insertGetId(['name' => 'Default', 'slug' => 'default']);

    expect(Product::findOrFail($id)->requires_hwid)->toBeTrue();
    expect((new Product)->requires_hwid)->toBeTrue();
});

test('restoring protection without baseline requires binding before verification', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['requires_hwid' => false]);
    app(DeviceBindings::class)->bind($user, $product, ['value' => 'pc']);
    Product::findOrFail($product->id)->update(['requires_hwid' => true]);

    expect(fn () => app(DeviceBindings::class)->verify($user, $product, ['value' => 'pc']))->toThrow(LicensingException::class, 'device not bound');

    app(DeviceBindings::class)->bind($user, $product, ['value' => 'pc']);
    expect(DeviceBinding::firstOrFail()->hwid)->toBe(['value' => 'pc']);
});

test('exempt operations accept different devices without changing bindings or emitting upgrades', function (string $operation, string $source, string $scope, bool $existing) {
    config(['licensekit.device_binding.scope' => $scope]);
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    if ($existing) {
        app(DeviceBindings::class)->bind($user, $product, ['value' => 'old']);
        DeviceBinding::firstOrFail()->update(['last_hwid_reset_at' => now()]);
    }
    grantHwidExemption($source, $user, $product);
    $before = DB::table('licensekit_device_bindings')->get()->toArray();
    Event::fake([ProductAccessInvalidated::class]);

    $result = exemptDeviceOperation($operation, $user, $product, ['value' => 'new']);

    if ($result !== null) {
        expect($result->hwid)->toBe(['value' => 'new']);
        expect($result->access->source)->toBe('paid');
    }
    expect(DB::table('licensekit_device_bindings')->get()->toArray())->toEqual($before);
    $this->assertDatabaseCount('licensekit_device_reset_history', 0);
    $this->assertDatabaseMissing('licensekit_audit_events', ['event' => 'hwid_upgraded']);
    Event::assertNotDispatched(ProductAccessInvalidated::class);
})->with('device operations')->with(['product', 'gate'])->with(['per_product', 'global'])->with([false, true]);

test('exempt operations still reject malformed HWID and host bans or failures', function (string $operation, string $source, string $failure) {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    grantHwidExemption($source, $user, $product);
    config(['licensekit.hwid_handler' => HostDevicePolicy::class, 'testing.blocked_device' => 'blocked', 'testing.policy_failure' => $failure === 'host-failure']);
    $hwid = $failure === 'invalid' ? [] : ['value' => 'blocked'];

    expect(fn () => exemptDeviceOperation($operation, $user, $product, $hwid))->toThrow(
        $failure === 'host-failure' ? RuntimeException::class : LicensingException::class,
        match ($failure) {
            'invalid' => 'invalid hwid',
            'host-ban' => 'device banned',
            'host-failure' => 'Host policy unavailable',
        },
    );

    $this->assertDatabaseCount('licensekit_device_bindings', 0);
})->with('device operations')->with(['product', 'gate'])->with(['invalid', 'host-ban', 'host-failure']);

test('host checks retain stored baseline while exempt', function (string $source) {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    app(DeviceBindings::class)->bind($user, $product, ['value' => 'old']);
    grantHwidExemption($source, $user, $product);
    config(['licensekit.hwid_handler' => HostDevicePolicy::class, 'testing.blocked_device' => 'old']);

    expect(fn () => app(LicensedDevices::class)->upgrade($user, $product, ['value' => 'new']))->toThrow(LicensingException::class, 'device banned');

    expect(DeviceBinding::firstOrFail()->hwid)->toBe(['value' => 'old']);
})->with(['product', 'gate']);

test('exemption does not grant licensed access or override universal restrictions', function (string $source, string $restriction) {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    if ($restriction !== 'no-license') {
        Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    }
    grantHwidExemption($source, $user, $product);
    if ($restriction === 'suspended') {
        $user->update(['is_suspended' => true]);
    } elseif ($restriction === 'blocked') {
        $product->update(['is_blocked' => true]);
    }

    expect(fn () => app(LicensedDevices::class)->bind($user, $product, ['value' => 'pc']))->toThrow(LicensingException::class, match ($restriction) {
        'no-license' => 'license unavailable',
        'suspended' => 'user unavailable',
        'blocked' => 'service unavailable',
    });

    $this->assertDatabaseCount('licensekit_device_bindings', 0);
})->with(['product', 'gate'])->with(['no-license', 'suspended', 'blocked']);

test('restoring protection uses current records and restores saved baseline', function (string $source, string $operation) {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    app(DeviceBindings::class)->bind($user, $product, ['value' => 'old']);
    grantHwidExemption($source, $user, $product);
    exemptDeviceOperation($operation, $user, $product, ['value' => 'new']);
    if ($source === 'product') {
        Product::findOrFail($product->id)->update(['requires_hwid' => true]);
    } else {
        Gate::define('bypassHwid', fn (): bool => false);
    }

    expect(fn () => exemptDeviceOperation($operation, $user, $product, ['value' => 'new']))->toThrow(LicensingException::class, 'hwid mismatch');

    expect(DeviceBinding::firstOrFail()->hwid)->toBe(['value' => 'old']);
})->with(['product', 'gate'])->with('device operations');

test('exempt product leaves shared global lock enforced for other products', function () {
    config(['licensekit.device_binding.scope' => 'global']);
    $user = User::factory()->create();
    $protected = Product::factory()->create();
    $exempt = Product::factory()->create(['requires_hwid' => false]);
    app(DeviceBindings::class)->bind($user, $protected, ['value' => 'old']);

    app(DeviceBindings::class)->bind($user, $exempt, ['value' => 'new']);

    expect(fn () => app(DeviceBindings::class)->verify($user, $protected, ['value' => 'new']))->toThrow(LicensingException::class, 'hwid mismatch');
    expect(DeviceBinding::firstOrFail()->hwid)->toBe(['value' => 'old']);
    $this->assertDatabaseCount('licensekit_device_bindings', 1);
});

test('exemption preserves customer and admin reset tools', function (string $source, bool $admin) {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $bindings = app(DeviceBindings::class);
    $bindings->bind($user, $product, ['value' => 'pc']);
    grantHwidExemption($source, $user, $product);

    if ($admin) {
        $bindings->adminReset($user, $product, testAdminActor(), 'Support cleanup');
    } else {
        $bindings->reset($user, $product, $user);
    }

    expect(DeviceBinding::firstOrFail()->hwid)->toBeNull();
    $this->assertDatabaseCount('licensekit_device_reset_history', 1);
    expect(AuditEvent::where('event', 'hwid_reset')->count())->toBe(1);
})->with(['product', 'gate'])->with([false, true]);
