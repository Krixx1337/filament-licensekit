<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use LicenseKit\Core\Actions\AccessPolicy;
use LicenseKit\Core\Actions\DeviceBindings;
use LicenseKit\Core\Actions\LicensedDevices;
use LicenseKit\Core\Actions\ManageEntitlements;
use LicenseKit\Core\Contracts\AccessRestriction;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\Product;
use LicenseKit\Tests\Core\Fixtures\ClientSessions;
use LicenseKit\Tests\Core\Fixtures\User;

class HostFreeRestriction implements AccessRestriction
{
    public function assertAllowed(Model $user, Product $product, ?Entitlement $entitlement): void
    {
        throw new LicensingException('service_unavailable');
    }
}

beforeEach(function () {
    $this->routes = false;
});

test('host free authorization works without module schema HTTP routes or panel', function () {
    $this->travelTo(now()->startOfSecond());
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $deadline = CarbonImmutable::now('UTC')->addHour();
    $result = app(LicensedDevices::class)->bind($user, $product, desktopRequest($user)['hwid'], $deadline);
    expect($result->access->source)->toBe('host_free')->and($result->access->entitlement)->toBeNull()
        ->and($result->access->validUntil->equalTo($deadline))->toBeTrue();
    app(LicensedDevices::class)->verify($user, $product, desktopRequest($user)['hwid'], $deadline);
    $this->assertDatabaseCount('licensekit_entitlements', 0);
    expect(Schema::hasTable('licensekit_free_access_windows'))->toBeFalse();
    expect(fn () => app(LicensedDevices::class)->verify($user, $product, desktopRequest($user, 'other')['hwid'], $deadline))->toThrow(LicensingException::class, 'hwid mismatch');
    $this->assertDatabaseCount('licensekit_device_bindings', 1);
});

test('null expired and exact-boundary host deadlines grant nothing', function (?int $offset) {
    $this->travelTo(now()->startOfSecond());
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $deadline = $offset === null ? null : CarbonImmutable::now()->addSeconds($offset);
    expect(fn () => app(LicensedDevices::class)->bind($user, $product, desktopRequest($user)['hwid'], $deadline))->toThrow(LicensingException::class, 'license unavailable');
    $this->assertDatabaseCount('licensekit_device_bindings', 0);
})->with([null, -1, 0]);

test('paid grant takes precedence and paid-only denial never uses free access implicitly', function () {
    $this->travelTo(now()->startOfSecond());
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    $result = app(AccessPolicy::class)->authorize($entitlement->user, $entitlement->product, CarbonImmutable::now()->addDay());
    expect($result->source)->toBe('paid')->and($result->entitlement->id)->toBe($entitlement->id);
    $entitlement->update(['expires_at' => now()->subDay()]);
    expect(fn () => app(AccessPolicy::class)->authorize($entitlement->user, $entitlement->product))->toThrow(LicensingException::class, 'license expired');
});

test('cancellation invalidates the paid grant but allows host-approved free access', function () {
    $this->travelTo(now()->startOfSecond());
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id, 'status' => 'revoked']);
    expect(fn () => app(AccessPolicy::class)->authorize($entitlement->user, $entitlement->product))->toThrow(LicensingException::class);
    $result = app(AccessPolicy::class)->authorize($entitlement->user, $entitlement->product, CarbonImmutable::now()->addHour());
    expect($result->source)->toBe('host_free')->and($result->entitlement)->toBeNull();
    expect($entitlement->fresh()->isRevoked())->toBeTrue();
});

test('universal restrictions deny host-approved free access without binding writes', function (string $restriction, string $error) {
    $this->travelTo(now()->startOfSecond());
    $user = User::factory()->create();
    $product = Product::factory()->create();
    match ($restriction) {
        'maintenance' => $product->update(['status' => 'maintenance']),
        'suspended' => $user->update(['is_suspended' => true]),
        'unverified' => $user->update(['email_verified_at' => null]),
        'custom' => config(['licensekit.access_restrictions' => [HostFreeRestriction::class]]),
    };
    expect(fn () => app(LicensedDevices::class)->bind($user, $product, desktopRequest($user)['hwid'], CarbonImmutable::now()->addHour()))->toThrow(LicensingException::class, str_replace('_', ' ', $error));
    $this->assertDatabaseCount('licensekit_device_bindings', 0);
})->with([['maintenance', 'service_unavailable'], ['suspended', 'user_unavailable'], ['unverified', 'user_unavailable'], ['custom', 'service_unavailable']]);

test('suspension denies free access before any purchase and invalidates host sessions', function () {
    $this->travelTo(now()->startOfSecond());
    $user = User::factory()->create();
    $admin = User::factory()->create();
    Gate::define('licensekit.manage', fn ($actor): bool => $actor->getKey() === $admin->getKey());
    $product = Product::factory()->create();
    config(['host_client.free_until' => now()->addHour()->toIso8601String()]);
    app(ClientSessions::class)->login($product, $user->email, 'password', desktopRequest($user)['hwid']);
    app(ManageEntitlements::class)->suspendCustomer($user, $product, $admin, 'Abuse');
    $this->assertDatabaseCount('client_sessions', 0);
    expect(fn () => app(AccessPolicy::class)->authorize($user, $product, CarbonImmutable::now()->addDay()))->toThrow(LicensingException::class, 'license suspended');
});

test('host session is deadline capped and heartbeat rechecks withdrawn free eligibility', function () {
    $this->travelTo(now()->startOfSecond());
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $end = CarbonImmutable::now()->addMinutes(2);
    config(['host_client.free_until' => $end->toIso8601String()]);
    $login = app(ClientSessions::class)->login($product, $user->email, 'password', desktopRequest($user)['hwid']);
    expect(CarbonImmutable::parse($login['expires_at'])->equalTo($end))->toBeTrue();
    config(['host_client.free_until' => null]);
    expect(fn () => app(ClientSessions::class)->heartbeat($product, $login['token'], desktopRequest($user)['hwid']))->toThrow(LicensingException::class, 'license unavailable');
});

test('restriction-only and standalone device operations do not create a paid or free grant', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    expect(app(AccessPolicy::class)->assertAllowed($user, $product))->toBeNull();
    expect(fn () => app(DeviceBindings::class)->verify($user, $product, desktopRequest($user)['hwid']))->toThrow(LicensingException::class, 'device not bound');
    app(DeviceBindings::class)->bind($user, $product, desktopRequest($user)['hwid']);
    app(DeviceBindings::class)->verify($user, $product, desktopRequest($user)['hwid']);
    expect(fn () => app(AccessPolicy::class)->authorize($user, $product))->toThrow(LicensingException::class);
    $this->assertDatabaseCount('licensekit_entitlements', 0);
});

test('host credential write failure rolls back first free binding in outer transaction', function () {
    $this->travelTo(now()->startOfSecond());
    $user = User::factory()->create();
    $product = Product::factory()->create();
    expect(fn () => DB::transaction(function () use ($user, $product): void {
        $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
        app(LicensedDevices::class)->bind($user, $product, desktopRequest($user)['hwid'], CarbonImmutable::now()->addDay());
        throw new RuntimeException('host credential failed');
    }))->toThrow(RuntimeException::class, 'host credential failed');
    $this->assertDatabaseCount('licensekit_device_bindings', 0);
});
