<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use LicenseKit\Core\Actions\LicensedDevices;
use LicenseKit\Core\Contracts\DeviceMatcher;
use LicenseKit\Core\Events\AccessRevoked;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\ActivityEntry;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\Product;
use LicenseKit\Tests\Core\Fixtures\User;

class PrefixDeviceMatcher implements DeviceMatcher
{
    public function matches(Product $product, string $bound, string $presented): bool
    {
        return explode(':', $bound)[0] === explode(':', $presented)[0];
    }
}

function lockedLicense(array $product = [], array $license = []): License
{
    return License::factory()->create(['user_id' => User::factory()->create()->id,
        'product_id' => Product::factory()->create($product)->id, ...$license]);
}

test('the first login locks the device and another PC is rejected', function () {
    $license = lockedLicense();
    $devices = app(LicensedDevices::class);

    expect(fn () => $devices->verify($license->user, $license->product, 'pc-a'))->toThrow(LicensingException::class, 'device not bound');
    expect($devices->bind($license->user, $license->product, 'pc-a')->device)->toBe('pc-a');
    expect($license->fresh()->device)->toBe('pc-a')->and($license->fresh()->device_bound_at)->not->toBeNull();
    expect($devices->verify($license->user, $license->product, 'pc-a')->access->source)->toBe('paid');
    expect(fn () => $devices->bind($license->user, $license->product, 'pc-b'))->toThrow(LicensingException::class, 'device mismatch');
    expect($license->fresh()->device)->toBe('pc-a');
    expect($license->fresh()->toArray())->not->toHaveKey('device');
});

test('invalid device strings are rejected without saving', function (string $device) {
    $license = lockedLicense();

    expect(fn () => app(LicensedDevices::class)->bind($license->user, $license->product, $device))->toThrow(LicensingException::class, 'invalid device');
    expect($license->fresh()->device)->toBeNull();
})->with(['blank' => ['   '], 'too long' => [str_repeat('a', 1025)]]);

test('a custom matcher can tolerate hardware changes', function () {
    config(['licensekit.device_matcher' => PrefixDeviceMatcher::class]);
    $license = lockedLicense(license: ['device' => 'board-1:disk-1']);

    expect(app(LicensedDevices::class)->verify($license->user, $license->product, 'board-1:disk-2')->device)->toBe('board-1:disk-2');
    expect($license->fresh()->device)->toBe('board-1:disk-1');
    expect(fn () => app(LicensedDevices::class)->verify($license->user, $license->product, 'board-2:disk-1'))->toThrow(LicensingException::class, 'device mismatch');
});

test('products without a device lock accept any PC and save nothing', function () {
    $license = lockedLicense(['device_lock' => false]);

    app(LicensedDevices::class)->bind($license->user, $license->product, 'pc-a');
    app(LicensedDevices::class)->verify($license->user, $license->product, 'pc-b');

    expect($license->fresh()->device)->toBeNull();
});

test('free access logins lock the device on a license row without paid time', function () {
    $this->travelTo(now()->startOfSecond());
    $user = User::factory()->create();
    $product = Product::factory()->create(['free_from' => now(), 'free_until' => now()->addHour()]);

    $auth = app(LicensedDevices::class)->bind($user, $product, 'pc-a');

    $license = License::where('user_id', $user->id)->sole();
    expect($auth->access->source)->toBe('free')->and($license->device)->toBe('pc-a')->and($license->expires_at)->toBeNull();
    $product->update(['free_until' => now()]);
    expect(fn () => app(LicensedDevices::class)->verify($user, $product, 'pc-a'))->toThrow(LicensingException::class, 'no license');
});

test('customers reset their own device once per product cooldown', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16T12:00:00Z'));
    $license = lockedLicense(['device_move_cooldown_days' => 7], ['device' => 'pc-a']);
    $devices = app(LicensedDevices::class);
    Event::fake([AccessRevoked::class]);

    $devices->reset($license->user, $license->product, 'New PC');

    expect($license->fresh()->device)->toBeNull()->and($license->fresh()->device_reset_at->toIso8601String())->toBe('2026-10-16T12:00:00+00:00');
    Event::assertDispatched(AccessRevoked::class, fn ($event): bool => $event->license->product_id === $license->product_id);
    $audit = ActivityEntry::where('event', 'device_reset')->sole();
    expect($audit->meta['before'])->toBe(['device' => 'pc-a'])->and($audit->meta['after']['performed_by'])->toBe('customer')->and($audit->meta['note'])->toBe('New PC');

    $devices->bind($license->user, $license->product, 'pc-b');
    expect(fn () => $devices->reset($license->user, $license->product))->toThrow(LicensingException::class, 'move cooldown');
    expect($devices->eligibility($license->fresh()))->toBe(['allowed' => false, 'available_at' => '2026-10-23T12:00:00+00:00', 'reason' => 'move_cooldown']);
    $this->travel(7)->days();
    $devices->reset($license->user, $license->product);
    expect($license->fresh()->device)->toBeNull();
});

test('support-only products refuse customer resets but staff can always reset', function () {
    $license = lockedLicense(['device_move_cooldown_days' => null], ['device' => 'pc-a', 'device_reset_at' => now()]);
    $devices = app(LicensedDevices::class);
    $admin = User::factory()->create();

    expect(fn () => $devices->reset($license->user, $license->product))->toThrow(LicensingException::class, 'customer reset disabled');

    $devices->adminReset($license->user, $license->product, $admin, 'Replaced motherboard');

    expect($license->fresh()->device)->toBeNull();
    $audit = ActivityEntry::where('event', 'device_reset')->sole();
    expect($audit->actor)->toBe('user:'.$admin->id)->and($audit->meta['after']['performed_by'])->toBe('admin');
});

test('moving the license to a new PC replaces the device in one step', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16T12:00:00Z'));
    $license = lockedLicense(['device_move_cooldown_days' => 1], ['device' => 'pc-a']);
    $devices = app(LicensedDevices::class);

    expect($devices->moveDevice($license->user, $license->product, 'pc-b')->device)->toBe('pc-b');

    expect($license->fresh()->device)->toBe('pc-b');
    expect(ActivityEntry::where('event', 'device_moved')->sole()->meta['before'])->toBe(['device' => 'pc-a']);
    expect(fn () => $devices->moveDevice($license->user, $license->product, 'pc-c'))->toThrow(LicensingException::class, 'move cooldown');
    $this->travel(1)->days();
    $devices->moveDevice($license->user, $license->product, 'pc-c');
    expect($license->fresh()->device)->toBe('pc-c');
});

test('resetting a license without a device reports device not bound', function () {
    $license = lockedLicense();

    expect(fn () => app(LicensedDevices::class)->reset($license->user, $license->product))->toThrow(LicensingException::class, 'device not bound');
    expect(fn () => app(LicensedDevices::class)->adminReset($license->user, $license->product, User::factory()->create(), 'Support'))
        ->toThrow(LicensingException::class, 'device not bound');
});
