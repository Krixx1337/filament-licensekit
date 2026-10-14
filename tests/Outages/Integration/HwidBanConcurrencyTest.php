<?php

use LicenseKit\Core\Actions\DeviceBindings;
use LicenseKit\Core\Models\AuditEvent;
use LicenseKit\Core\Models\DeviceBinding;
use LicenseKit\Core\Models\DeviceResetHistory;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\Product;
use LicenseKit\Tests\Core\Fixtures\StructuredHwid;
use LicenseKit\Tests\Outages\Fixtures\User;

test('MySQL competing rebinds keep the cleared device on the reset row', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    app(DeviceBindings::class)->bind($user, $product, ['value' => 'old']);
    app(DeviceBindings::class)->reset($user, $product, $user);

    $results = $this->race(['hwid-rebind', 'hwid-rebind'], ['user_id' => $user->id, 'product_id' => $product->id]);

    expect($results)->toContain(1)->toContain('hwid_mismatch');
    expect(DeviceResetHistory::firstOrFail()->before_hwid)->toBe(['value' => 'old']);
    expect(DeviceBinding::firstOrFail()->hwid)->not->toBeNull();
    $this->assertDatabaseCount('licensekit_device_reset_history', 1);
});

test('MySQL competing upgrades cannot overwrite newer baseline with older version', function () {
    config(['licensekit.hwid_handler' => StructuredHwid::class]);
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    app(DeviceBindings::class)->bind($user, $product, ['v' => 1, 'id' => 'pc']);

    $results = $this->race(['hwid-upgrade', 'hwid-upgrade'], ['user_id' => $user->id, 'product_id' => $product->id, 'structured_hwid' => true]);

    expect($results)->toContain(1);
    expect(DeviceBinding::firstOrFail()->hwid)->toBe(['id' => 'pc', 'v' => 3]);
    expect(AuditEvent::where('event', 'hwid_upgraded')->count())->toBe(count(array_filter($results, fn ($result) => $result === 1)));
    $this->assertDatabaseCount('licensekit_device_reset_history', 0);
});

test('MySQL reset and upgrade serialize without rebinding cleared device', function (bool $resetFirst) {
    config(['licensekit.hwid_handler' => StructuredHwid::class]);
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    app(DeviceBindings::class)->bind($user, $product, ['v' => 1, 'id' => 'pc']);

    $results = $this->race(['device-reset', 'hwid-upgrade'], ['user_id' => $user->id, 'product_id' => $product->id, 'reset_product_id' => $product->id, 'structured_hwid' => true, 'upgrade_version' => 2], $resetFirst ? 'device-reset' : 'hwid-upgrade');

    expect($results[0])->toBe(1)->and($results[1])->toBe($resetFirst ? 'device_not_bound' : 1);
    expect(DeviceBinding::firstOrFail()->hwid)->toBeNull();
    $history = DeviceResetHistory::firstOrFail();
    expect($history->before_hwid['v'])->toBe($resetFirst ? 1 : 2);
})->with([false, true]);
