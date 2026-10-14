<?php

use LicenseKit\Core\Actions\DeviceBindings;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\AuditEvent;
use LicenseKit\Core\Models\DeviceBinding;
use LicenseKit\Core\Models\DeviceResetHistory;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\Product;
use LicenseKit\Tests\Core\Fixtures\ClientSessions;
use LicenseKit\Tests\Core\Fixtures\HostDevicePolicy;
use LicenseKit\Tests\Core\Fixtures\User;

test('reset stores the cleared device and a later bind leaves that row unchanged', function (string $scope) {
    config(['licensekit.device_binding.scope' => $scope]);
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $before = desktopRequest($user, 'old')['hwid'];
    $after = desktopRequest($user, 'new')['hwid'];
    app(DeviceBindings::class)->bind($user, $product, $before);
    app(DeviceBindings::class)->reset($user, $product, $user);
    $history = DeviceResetHistory::firstOrFail();
    expect($history->before_hwid)->toBe($before);
    $this->travel(1)->minute();

    app(DeviceBindings::class)->bind($user, $product, $after);
    expect($history->fresh()->before_hwid)->toBe($before)->and($history->fresh()->getAttributes())->toBe($history->getAttributes());
    expect(DeviceBinding::firstOrFail()->hwid)->toBe($after);
    $this->assertDatabaseCount('licensekit_device_reset_history', 1);
    $this->travel(7)->days();
    app(DeviceBindings::class)->reset($user, $product, $user);
    app(DeviceBindings::class)->bind($user, $product, desktopRequest($user, 'third')['hwid']);
    expect($history->fresh()->before_hwid)->toBe($before);
    expect(DeviceResetHistory::orderByDesc('id')->first()->before_hwid)->toBe($after);
})->with(['per_product', 'global']);

test('failed credential issuance rolls back binding and snapshot completion', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    app(DeviceBindings::class)->bind($user, $product, desktopRequest($user, 'old')['hwid']);
    app(DeviceBindings::class)->reset($user, $product, $user);
    AuditEvent::creating(function (AuditEvent $event): void {
        if ($event->event === 'client_login') {
            throw new RuntimeException('Issuance failed');
        }
    });

    expect(fn () => app(ClientSessions::class)->login($product, $user->email, 'password', desktopRequest($user, 'new')['hwid']))->toThrow(RuntimeException::class, 'Issuance failed');
    expect(DeviceBinding::firstOrFail()->hwid)->toBeNull();
    expect(DeviceResetHistory::firstOrFail()->before_hwid)->toBe(desktopRequest($user, 'old')['hwid']);
    $this->assertDatabaseCount('client_sessions', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('rejected banned rebind captures no attempts', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    app(DeviceBindings::class)->bind($user, $product, desktopRequest($user, 'old')['hwid']);
    app(DeviceBindings::class)->reset($user, $product, $user);
    $hwid = desktopRequest($user, 'rejected')['hwid'];
    config(['licensekit.hwid_handler' => HostDevicePolicy::class, 'testing.blocked_device' => 'rejected']);

    expect(fn () => app(DeviceBindings::class)->bind($user, $product, $hwid))->toThrow(LicensingException::class, 'device banned');
    expect(DeviceResetHistory::firstOrFail()->before_hwid)->toBe(desktopRequest($user, 'old')['hwid']);
    $this->assertDatabaseCount('licensekit_device_reset_history', 1);
});

test('history preserves nested host payloads without requiring current handler support', function () {
    $before = ['v' => 1, 'ids' => ['board' => ['serial' => 'old'], 'disks' => ['a', 'b']]];
    $history = DeviceResetHistory::factory()->create(['before_hwid' => $before]);
    config(['licensekit.hwid_handler' => 'RetiredHandler']);

    expect($history->fresh()->before_hwid)->toBe($before);
});
