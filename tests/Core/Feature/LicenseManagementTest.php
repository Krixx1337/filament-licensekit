<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use LicenseKit\Core\Actions\AccessPolicy;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\CustomerLicenseSummary;
use LicenseKit\Core\Actions\LicenseClock;
use LicenseKit\Core\Actions\LicensedDevices;
use LicenseKit\Core\Actions\ManageLicenses;
use LicenseKit\Core\Events\LicenseAccessInvalidated;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\AuditEvent;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Tests\Core\Fixtures\LicenseResource;
use LicenseKit\Tests\Core\Fixtures\User;

test('individual extensions preserve future time and reactivate expired licenses', function (string $expiry, string $expected) {
    $this->travelTo(CarbonImmutable::parse('2026-01-01T00:00:00Z'));
    $actor = testAdminActor();
    $license = License::factory()->create(['user_id' => User::factory()->create()->id, 'expires_at' => $expiry]);

    app(ManageLicenses::class)->extend($license, 3600, $actor, ' Support credit ');

    expect($license->fresh()->expires_at->toIso8601String())->toBe($expected);
    $this->assertDatabaseHas('licensekit_audit_events', ['event' => 'license_extended', 'reason' => 'Support credit']);
    expect(AuditEvent::where('event', 'license_extended')->first()->after['added_seconds'])->toBe(3600);
})->with([
    'active' => ['2026-01-02 00:00:00', '2026-01-02T01:00:00+00:00'],
    'expired' => ['2025-12-31 00:00:00', '2026-01-01T01:00:00+00:00'],
    'boundary' => ['2026-01-01 00:00:00', '2026-01-01T01:00:00+00:00'],
]);

test('pause banks seconds and resume starts extended balance from now without removing blocks', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-01T02:00:00.500000+02:00'));
    $actor = testAdminActor();
    $license = License::factory()->create(['user_id' => User::factory()->create()->id, 'expires_at' => '2026-01-01 01:00:01']);
    $tools = app(ManageLicenses::class);
    Event::fake([LicenseAccessInvalidated::class]);

    $tools->pause($license, $actor, 'Vacation');

    expect($license->fresh()->remaining_seconds)->toBe(3601);
    expect($license->fresh()->paused_at->toIso8601String())->toBe('2026-01-01T00:00:00+00:00');
    expect($license->fresh()->expires_at)->toBeNull();
    expect(app(LicenseClock::class)->active($license->fresh()))->toBeFalse();
    Event::assertDispatched(LicenseAccessInvalidated::class, fn ($event): bool => $event->license->id === $license->id);

    $this->travelTo(CarbonImmutable::parse('2026-02-01T00:00:00Z'));
    $tools->extend($license, 3600, $actor, 'Support');
    expect($license->fresh()->remaining_seconds)->toBe(7201);
    expect($license->fresh()->expires_at)->toBeNull();
    $tools->resume($license, $actor, 'Returned');

    expect($license->fresh()->expires_at->toIso8601String())->toBe('2026-02-01T02:00:01+00:00');
    expect($license->fresh()->paused_at)->toBeNull();
    expect($license->fresh()->remaining_seconds)->toBeNull();
    expect(AuditEvent::where('event', 'like', 'license_%')->pluck('event')->all())->toBe(['license_paused', 'license_extended', 'license_resumed']);
});

test('clock changes reject invalid reasons without writes', function (string $operation, string $reason) {
    $actor = testAdminActor();
    $license = License::factory()->create(['user_id' => User::factory()->create()->id]);
    $before = $license->fresh()->getAttributes();
    $tools = app(ManageLicenses::class);

    expect(fn () => $operation === 'extend' ? $tools->extend($license, 1, $actor, $reason) : $tools->{$operation}($license, $actor, $reason))->toThrow(LicensingException::class, 'invalid reason');

    expect($license->fresh()->getAttributes())->toBe($before);
    expect(AuditEvent::where('event', 'like', 'license_%')->count())->toBe(0);
})->with(['extend', 'pause', 'resume'])->with([' ', str_repeat('x', 2001)]);

test('extension validates seconds at domain boundary', function (int $seconds) {
    $actor = testAdminActor();
    $license = License::factory()->create(['user_id' => User::factory()->create()->id]);
    $before = $license->expires_at;

    expect(fn () => app(ManageLicenses::class)->extend($license, $seconds, $actor, 'Support'))->toThrow(LicensingException::class, 'invalid duration');

    expect($license->fresh()->expires_at->equalTo($before))->toBeTrue();
    expect(AuditEvent::where('event', 'like', 'license_%')->count())->toBe(0);
})->with([0, -1, 315360001]);

test('extension accepts duration boundaries without timestamp overflow', function (int $seconds, string $expected) {
    $this->travelTo(CarbonImmutable::parse('2038-01-19T03:14:07Z'));
    $actor = testAdminActor();
    $license = License::factory()->expired()->create(['user_id' => User::factory()->create()->id]);

    app(ManageLicenses::class)->extend($license, $seconds, $actor, 'Support');

    expect($license->fresh()->expires_at->toIso8601String())->toBe($expected);
})->with([[1, '2038-01-19T03:14:08+00:00'], [315360000, '2048-01-17T03:14:07+00:00']]);

test('clock changes reject unavailable licenses', function (string $operation, array $state) {
    $actor = testAdminActor();
    $license = License::factory()->create(['user_id' => User::factory()->create()->id, ...$state]);
    $before = $license->fresh()->getAttributes();
    $tools = app(ManageLicenses::class);

    expect(fn () => $operation === 'extend' ? $tools->extend($license, 1, $actor, 'Support') : $tools->{$operation}($license, $actor, 'Support'))->toThrow(LicensingException::class, 'cannot '.$operation.' license');

    expect($license->fresh()->getAttributes())->toBe($before);
    expect(AuditEvent::where('event', 'like', 'license_%')->count())->toBe(0);
})->with(['extend', 'pause', 'resume'])->with([
    'lifetime' => [['is_lifetime' => true, 'expires_at' => null]],
    'revoked' => [['status' => 'revoked']],
    'suspended' => [['status' => 'suspended']],
    'deleted owner' => [['user_id' => null]],
]);

test('pause and resume reject repeated transitions and invalid balances', function () {
    $actor = testAdminActor();
    $license = License::factory()->create(['user_id' => User::factory()->create()->id]);
    $tools = app(ManageLicenses::class);
    $tools->pause($license, $actor, 'Vacation');

    expect(fn () => $tools->pause($license, $actor, 'Again'))->toThrow(LicensingException::class, 'cannot pause license');
    $tools->resume($license, $actor, 'Returned');
    expect(fn () => $tools->resume($license, $actor, 'Again'))->toThrow(LicensingException::class, 'cannot resume license');
    $license->update(['paused_at' => now(), 'expires_at' => null, 'remaining_seconds' => 0]);
    expect(fn () => $tools->resume($license, $actor, 'Invalid'))->toThrow(LicensingException::class, 'cannot resume license');
    expect(AuditEvent::where('event', 'like', 'license_%')->count())->toBe(2);
});

test('expired licenses cannot be paused including exact expiry', function (int $offset) {
    $this->travelTo(CarbonImmutable::parse('2026-01-01T00:00:00Z'));
    $actor = testAdminActor();
    $license = License::factory()->create(['user_id' => User::factory()->create()->id, 'expires_at' => now()->addSeconds($offset)]);

    expect(fn () => app(ManageLicenses::class)->pause($license, $actor, 'Vacation'))->toThrow(LicensingException::class, 'cannot pause license');

    expect($license->fresh()->paused_at)->toBeNull();
})->with([0, -1]);

test('paused licenses reject both key durations and direct clock grants without consuming keys', function (?int $days) {
    $user = User::factory()->create();
    $license = License::factory()->paused()->create(['user_id' => $user->id]);
    $plan = LicensePlan::factory()->create(['product_id' => $license->product_id]);
    $key = LicenseKey::factory()->create(['license_plan_id' => $plan->id, 'duration_days' => $days]);

    expect(fn () => app(ClaimLicense::class)->claim($user, $key->key))->toThrow(LicensingException::class, 'subscription paused');
    expect(fn () => app(LicenseClock::class)->grant($license, $days === null ? null : 86400))->toThrow(LicensingException::class, 'subscription paused');

    expect($key->fresh()->claimed_at)->toBeNull();
    expect($license->fresh()->remaining_seconds)->toBe(86400);
    expect($license->fresh()->is_lifetime)->toBeFalse();
})->with([30, null]);

test('pause denies bind verify free access and shared access checks until resumed', function () {
    installHostDesktopProtocol();
    $actor = testAdminActor();
    $user = User::factory()->create();
    $license = License::factory()->create(['user_id' => $user->id]);
    $devices = app(LicensedDevices::class);
    $devices->bind($user, $license->product, 'pc');
    app(ManageLicenses::class)->pause($license, $actor, 'Vacation');

    expect(fn () => $devices->bind($user, $license->product, 'pc'))->toThrow(LicensingException::class, 'subscription paused');
    expect(fn () => $devices->verify($user, $license->product, 'pc'))->toThrow(LicensingException::class, 'subscription paused');
    expect(fn () => app(AccessPolicy::class)->authorize($user, $license->product, CarbonImmutable::now()->addDay()))->toThrow(LicensingException::class, 'subscription paused');
    expect(fn () => app(AccessPolicy::class)->assertAllowed($user, $license->product))->toThrow(LicensingException::class, 'subscription paused');
    $this->postJson('/host/products/'.$license->product->slug.'/login', desktopRequest($user))->assertForbidden()->assertJsonPath('error', 'subscription_paused');

    app(ManageLicenses::class)->resume($license, $actor, 'Returned');
    expect($devices->verify($user, $license->product, 'pc')->access->source)->toBe('paid');
});

test('paused summaries expose balance and preserve restriction precedence', function (array $state, string $status) {
    $license = License::factory()->paused(3601)->create(['user_id' => User::factory()->create()->id, ...$state]);

    $summary = app(CustomerLicenseSummary::class)->for($license);
    $resource = (new LicenseResource($license))->toArray(Request::create('/'));

    expect($summary['status'])->toBe($status);
    expect($summary['remaining_seconds'])->toBe(3601);
    expect($resource['paused_at'])->toBe($summary['paused_at']);
    expect($resource['remaining_seconds'])->toBe(3601);
    expect($resource['lifetime'])->toBeFalse();
})->with([[[], 'paused'], [['status' => 'suspended'], 'suspended'], [['status' => 'revoked'], 'revoked']]);

test('clock mutations roll back on audit failure', function (string $operation) {
    $actor = testAdminActor();
    $license = License::factory()->create(['user_id' => User::factory()->create()->id]);
    if ($operation === 'resume') {
        $license->update(['paused_at' => now(), 'remaining_seconds' => 3600, 'expires_at' => null]);
    }
    $before = $license->fresh()->getAttributes();
    Event::listen('eloquent.creating: '.AuditEvent::class, fn () => throw new RuntimeException('Audit failed'));
    $tools = app(ManageLicenses::class);

    expect(fn () => $operation === 'extend' ? $tools->extend($license, 1, $actor, 'Support') : $tools->{$operation}($license, $actor, 'Support'))->toThrow(RuntimeException::class, 'Audit failed');

    expect($license->fresh()->getAttributes())->toBe($before);
})->with(['extend', 'pause', 'resume']);

test('pause and host session cleanup roll back together when invalidation listener fails', function () {
    installHostDesktopProtocol();
    $actor = testAdminActor();
    $user = User::factory()->create();
    $license = License::factory()->create(['user_id' => $user->id]);
    $this->postJson('/host/products/'.$license->product->slug.'/login', desktopRequest($user))->assertOk();
    Event::listen(LicenseAccessInvalidated::class, fn () => throw new RuntimeException('Cleanup failed'));

    expect(fn () => app(ManageLicenses::class)->pause($license, $actor, 'Vacation'))->toThrow(RuntimeException::class, 'Cleanup failed');

    expect($license->fresh()->paused_at)->toBeNull();
    $this->assertDatabaseCount('host_desktop_sessions', 1);
    expect(AuditEvent::where('event', 'like', 'license_%')->count())->toBe(0);
});

test('pause invalidates host desktop sessions', function () {
    installHostDesktopProtocol();
    $actor = testAdminActor();
    $user = User::factory()->create();
    $license = License::factory()->create(['user_id' => $user->id]);
    $this->postJson('/host/products/'.$license->product->slug.'/login', desktopRequest($user))->assertOk();

    app(ManageLicenses::class)->pause($license, $actor, 'Vacation');

    $this->assertDatabaseCount('host_desktop_sessions', 0);
});
