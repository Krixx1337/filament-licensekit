<?php

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LicenseKit\Core\Actions\DeviceBindings;
use LicenseKit\Core\Actions\LicensedDevices;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\AuditEvent;
use LicenseKit\Core\Models\DeviceBinding;
use LicenseKit\Core\Models\DeviceResetHistory;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\Product;
use LicenseKit\Tests\Core\Fixtures\ClientSession;
use LicenseKit\Tests\Core\Fixtures\ClientSessions;
use LicenseKit\Tests\Core\Fixtures\HostDevicePolicy;
use LicenseKit\Tests\Core\Fixtures\User;

test('host device policy denies every public device operation without mutations', function (string $operation, bool $existing) {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    if ($existing) {
        app(DeviceBindings::class)->bind($user, $product, ['value' => 'pc']);
    }
    config(['licensekit.hwid_handler' => HostDevicePolicy::class, 'testing.blocked_device' => 'pc']);
    $before = DB::table('licensekit_device_bindings')->get()->toArray();

    expect(fn () => match ($operation) {
        'bind' => app(DeviceBindings::class)->bind($user, $product, ['value' => 'pc']),
        'verify' => app(DeviceBindings::class)->verify($user, $product, ['value' => 'pc']),
        'licensed-bind' => app(LicensedDevices::class)->bind($user, $product, ['value' => 'pc']),
        'licensed-verify' => app(LicensedDevices::class)->verify($user, $product, ['value' => 'pc']),
        'upgrade' => app(LicensedDevices::class)->upgrade($user, $product, ['value' => 'pc']),
    })->toThrow(LicensingException::class, $operation === 'upgrade' && ! $existing ? 'device not bound' : 'device banned');

    expect(DB::table('licensekit_device_bindings')->get()->toArray())->toEqual($before);
    $this->assertDatabaseCount('licensekit_device_reset_history', 0);
})->with(['bind', 'verify', 'licensed-bind', 'licensed-verify', 'upgrade'])->with([false, true]);

test('host policy receives stored baseline and trusted product context', function (string $scope) {
    config(['licensekit.device_binding.scope' => $scope]);
    $user = User::factory()->create();
    $products = Product::factory()->count(2)->create();
    app(DeviceBindings::class)->bind($user, $products[0], ['value' => 'old']);
    config(['licensekit.hwid_handler' => HostDevicePolicy::class, 'testing.blocked_device' => 'old', 'testing.blocked_product' => $products[0]->id]);

    expect(fn () => app(DeviceBindings::class)->verify($user, $products[0], ['value' => 'new']))->toThrow(LicensingException::class, 'device banned');
    app(DeviceBindings::class)->bind($user, $products[1], ['value' => $scope === 'global' ? 'old' : 'new']);
    expect(DeviceBinding::firstOrFail()->hwid)->toBe(['value' => 'old']);
})->with(['per_product', 'global']);

test('host policy denies login and heartbeat without issuing or renewing credentials', function (bool $free) {
    $this->freezeTime();
    $user = User::factory()->create();
    $product = Product::factory()->create();
    if ($free) {
        config(['host_client.free_until' => now()->addHour()->toIso8601String()]);
    } else {
        Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    }
    $hwid = ['value' => 'pc'];
    $token = app(ClientSessions::class)->login($product, $user->email, 'password', $hwid)['token'];
    $before = DB::table('personal_access_tokens')->first();
    config(['licensekit.hwid_handler' => HostDevicePolicy::class, 'testing.blocked_device' => 'pc']);
    $this->travel(1)->minute();

    expect(fn () => app(ClientSessions::class)->heartbeat($product, $token, $hwid))->toThrow(LicensingException::class, 'device banned');
    expect(fn () => app(ClientSessions::class)->login($product, $user->email, 'password', $hwid))->toThrow(LicensingException::class, 'device banned');
    expect(DB::table('personal_access_tokens')->first())->toEqual($before);
    $this->assertDatabaseCount('client_sessions', 1);
    config(['testing.blocked_device' => 'another-device']);
    app(ClientSessions::class)->heartbeat($product, $token, $hwid);
})->with([false, true]);

test('unexpected host policy failures deny binding', function () {
    config(['licensekit.hwid_handler' => HostDevicePolicy::class, 'testing.policy_failure' => true]);
    $user = User::factory()->create();
    $product = Product::factory()->create();

    expect(fn () => app(DeviceBindings::class)->bind($user, $product, ['value' => 'pc']))->toThrow(RuntimeException::class, 'Host policy unavailable');
    $this->assertDatabaseCount('licensekit_device_bindings', 0);
});

test('bindings sessions and reset snapshots use configured private storage', function (string $storage) {
    config(['licensekit.device_binding.storage' => $storage]);
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $hwid = ['value' => 'readable-secret'];
    app(ClientSessions::class)->login($product, $user->email, 'password', $hwid);
    foreach ([DeviceBinding::firstOrFail(), ClientSession::firstOrFail()] as $record) {
        expect($record->hwid)->toBe($hwid)->and($record->toArray())->not->toHaveKey('hwid');
        expect(str_contains($record->getRawOriginal('hwid'), 'readable-secret'))->toBe($storage === 'plain');
    }
    app(DeviceBindings::class)->reset($user, $product, $user);
    $history = DeviceResetHistory::firstOrFail();
    expect($history->before_hwid)->toBe($hwid);
    expect($history->toArray())->not->toHaveKey('before_hwid');
    expect(str_contains($history->getRawOriginal('before_hwid'), 'readable-secret'))->toBe($storage === 'plain');
    expect(AuditEvent::all()->toJson())->not->toContain('readable-secret');
})->with(['plain', 'encrypted']);

test('corrupt binding storage fails closed instead of binding a new device', function (string $storage, string $corrupt) {
    config(['licensekit.device_binding.storage' => $storage]);
    $user = User::factory()->create();
    $product = Product::factory()->create();
    app(DeviceBindings::class)->bind($user, $product, ['value' => 'pc']);
    DB::table('licensekit_device_bindings')->update(['hwid' => $corrupt]);

    expect(fn () => app(DeviceBindings::class)->bind($user, $product, ['value' => 'other']))->toThrow(LicensingException::class, 'licensing unavailable');
    expect(DB::table('licensekit_device_bindings')->first()->hwid)->toBe($corrupt);
})->with([['plain', 'null'], ['plain', '{broken'], ['plain', '[]'], ['plain', '{}'], ['plain', '"pc"'], ['plain', '{"value":12}'], ['encrypted', 'garbage']]);

test('missing or wrong encryption key cannot read stored HWID', function (string $key) {
    config(['licensekit.device_binding.storage' => 'encrypted']);
    $user = User::factory()->create();
    $product = Product::factory()->create();
    app(DeviceBindings::class)->bind($user, $product, ['value' => 'pc']);
    config(['app.key' => $key]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');

    expect(fn () => app(DeviceBindings::class)->verify($user, $product, ['value' => 'pc']))->toThrow(LicensingException::class, 'licensing unavailable');
})->with(['', 'base64:'.base64_encode(str_repeat('b', 32))]);

test('corrupt snapshots and session payloads cannot be inspected', function (string $storage, string $store) {
    config(['licensekit.device_binding.storage' => $storage]);
    [$record, $column] = match ($store) {
        'before' => [DeviceResetHistory::factory()->create(['before_hwid' => ['value' => 'pc']]), 'before_hwid'],
        'session' => [ClientSession::factory()->create(['user_id' => User::factory()->create()->id]), 'hwid'],
    };
    DB::table($record->getTable())->where('id', $record->id)->update([$column => 'broken']);

    expect(fn () => $record->fresh()->getAttribute($column))->toThrow(LicensingException::class, 'licensing unavailable');
})->with(['plain', 'encrypted'])->with(['before', 'session']);
