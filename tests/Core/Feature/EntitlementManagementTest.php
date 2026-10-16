<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use LicenseKit\Core\Actions\AccessPolicy;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\CustomerLicenseSummary;
use LicenseKit\Core\Actions\EntitlementClock;
use LicenseKit\Core\Actions\LicensedDevices;
use LicenseKit\Core\Actions\ManageEntitlements;
use LicenseKit\Core\Events\EntitlementAccessInvalidated;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Http\Resources\EntitlementResource;
use LicenseKit\Core\Models\AuditEvent;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Tests\Core\Fixtures\User;

test('individual extensions preserve future time and reactivate expired licenses', function (string $expiry, string $expected) {
    $this->travelTo(CarbonImmutable::parse('2026-01-01T00:00:00Z'));
    $actor = testAdminActor();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id, 'expires_at' => $expiry]);

    app(ManageEntitlements::class)->extend($entitlement, 3600, $actor, ' Support credit ');

    expect($entitlement->fresh()->expires_at->toIso8601String())->toBe($expected);
    $this->assertDatabaseHas('licensekit_audit_events', ['event' => 'entitlement_extended', 'reason' => 'Support credit']);
    expect(AuditEvent::where('event', 'entitlement_extended')->first()->after['added_seconds'])->toBe(3600);
})->with([
    'active' => ['2026-01-02 00:00:00', '2026-01-02T01:00:00+00:00'],
    'expired' => ['2025-12-31 00:00:00', '2026-01-01T01:00:00+00:00'],
    'boundary' => ['2026-01-01 00:00:00', '2026-01-01T01:00:00+00:00'],
]);

test('pause banks seconds and resume starts extended balance from now without removing blocks', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-01T02:00:00.500000+02:00'));
    $actor = testAdminActor();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id, 'expires_at' => '2026-01-01 01:00:01']);
    $tools = app(ManageEntitlements::class);
    Event::fake([EntitlementAccessInvalidated::class]);

    $tools->pause($entitlement, $actor, 'Vacation');

    expect($entitlement->fresh()->remaining_seconds)->toBe(3601);
    expect($entitlement->fresh()->paused_at->toIso8601String())->toBe('2026-01-01T00:00:00+00:00');
    expect($entitlement->fresh()->expires_at)->toBeNull();
    expect(app(EntitlementClock::class)->active($entitlement->fresh()))->toBeFalse();
    Event::assertDispatched(EntitlementAccessInvalidated::class, fn ($event): bool => $event->entitlement->id === $entitlement->id);

    $this->travelTo(CarbonImmutable::parse('2026-02-01T00:00:00Z'));
    $tools->extend($entitlement, 3600, $actor, 'Support');
    expect($entitlement->fresh()->remaining_seconds)->toBe(7201);
    expect($entitlement->fresh()->expires_at)->toBeNull();
    $tools->resume($entitlement, $actor, 'Returned');

    expect($entitlement->fresh()->expires_at->toIso8601String())->toBe('2026-02-01T02:00:01+00:00');
    expect($entitlement->fresh()->paused_at)->toBeNull();
    expect($entitlement->fresh()->remaining_seconds)->toBeNull();
    expect(AuditEvent::where('event', 'like', 'entitlement_%')->pluck('event')->all())->toBe(['entitlement_paused', 'entitlement_extended', 'entitlement_resumed']);
});

test('clock changes reject invalid reasons without writes', function (string $operation, string $reason) {
    $actor = testAdminActor();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    $before = $entitlement->fresh()->getAttributes();
    $tools = app(ManageEntitlements::class);

    expect(fn () => $operation === 'extend' ? $tools->extend($entitlement, 1, $actor, $reason) : $tools->{$operation}($entitlement, $actor, $reason))->toThrow(LicensingException::class, 'invalid reason');

    expect($entitlement->fresh()->getAttributes())->toBe($before);
    expect(AuditEvent::where('event', 'like', 'entitlement_%')->count())->toBe(0);
})->with(['extend', 'pause', 'resume'])->with([' ', str_repeat('x', 2001)]);

test('extension validates seconds at domain boundary', function (int $seconds) {
    $actor = testAdminActor();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    $before = $entitlement->expires_at;

    expect(fn () => app(ManageEntitlements::class)->extend($entitlement, $seconds, $actor, 'Support'))->toThrow(LicensingException::class, 'invalid duration');

    expect($entitlement->fresh()->expires_at->equalTo($before))->toBeTrue();
    expect(AuditEvent::where('event', 'like', 'entitlement_%')->count())->toBe(0);
})->with([0, -1, 315360001]);

test('extension accepts duration boundaries without timestamp overflow', function (int $seconds, string $expected) {
    $this->travelTo(CarbonImmutable::parse('2038-01-19T03:14:07Z'));
    $actor = testAdminActor();
    $entitlement = Entitlement::factory()->expired()->create(['user_id' => User::factory()->create()->id]);

    app(ManageEntitlements::class)->extend($entitlement, $seconds, $actor, 'Support');

    expect($entitlement->fresh()->expires_at->toIso8601String())->toBe($expected);
})->with([[1, '2038-01-19T03:14:08+00:00'], [315360000, '2048-01-17T03:14:07+00:00']]);

test('clock changes reject unavailable licenses', function (string $operation, array $state) {
    $actor = testAdminActor();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id, ...$state]);
    $before = $entitlement->fresh()->getAttributes();
    $tools = app(ManageEntitlements::class);

    expect(fn () => $operation === 'extend' ? $tools->extend($entitlement, 1, $actor, 'Support') : $tools->{$operation}($entitlement, $actor, 'Support'))->toThrow(LicensingException::class, 'cannot '.$operation.' entitlement');

    expect($entitlement->fresh()->getAttributes())->toBe($before);
    expect(AuditEvent::where('event', 'like', 'entitlement_%')->count())->toBe(0);
})->with(['extend', 'pause', 'resume'])->with([
    'lifetime' => [['is_lifetime' => true, 'expires_at' => null]],
    'revoked' => [['status' => 'revoked']],
    'suspended' => [['status' => 'suspended']],
    'deleted owner' => [['user_id' => null]],
]);

test('pause and resume reject repeated transitions and invalid balances', function () {
    $actor = testAdminActor();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    $tools = app(ManageEntitlements::class);
    $tools->pause($entitlement, $actor, 'Vacation');

    expect(fn () => $tools->pause($entitlement, $actor, 'Again'))->toThrow(LicensingException::class, 'cannot pause entitlement');
    $tools->resume($entitlement, $actor, 'Returned');
    expect(fn () => $tools->resume($entitlement, $actor, 'Again'))->toThrow(LicensingException::class, 'cannot resume entitlement');
    $entitlement->update(['paused_at' => now(), 'expires_at' => null, 'remaining_seconds' => 0]);
    expect(fn () => $tools->resume($entitlement, $actor, 'Invalid'))->toThrow(LicensingException::class, 'cannot resume entitlement');
    expect(AuditEvent::where('event', 'like', 'entitlement_%')->count())->toBe(2);
});

test('expired licenses cannot be paused including exact expiry', function (int $offset) {
    $this->travelTo(CarbonImmutable::parse('2026-01-01T00:00:00Z'));
    $actor = testAdminActor();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id, 'expires_at' => now()->addSeconds($offset)]);

    expect(fn () => app(ManageEntitlements::class)->pause($entitlement, $actor, 'Vacation'))->toThrow(LicensingException::class, 'cannot pause entitlement');

    expect($entitlement->fresh()->paused_at)->toBeNull();
})->with([0, -1]);

test('paused licenses reject both key durations and direct clock grants without consuming keys', function (?int $days) {
    $user = User::factory()->create();
    $entitlement = Entitlement::factory()->paused()->create(['user_id' => $user->id]);
    $plan = LicensePlan::factory()->create(['product_id' => $entitlement->product_id]);
    $key = LicenseKey::factory()->create(['license_plan_id' => $plan->id, 'duration_days' => $days]);

    expect(fn () => app(ClaimLicense::class)->claim($user, $key->key))->toThrow(LicensingException::class, 'subscription paused');
    expect(fn () => app(EntitlementClock::class)->grant($entitlement, $days === null ? null : 86400))->toThrow(LicensingException::class, 'subscription paused');

    expect($key->fresh()->claimed_at)->toBeNull();
    expect($entitlement->fresh()->remaining_seconds)->toBe(86400);
    expect($entitlement->fresh()->is_lifetime)->toBeFalse();
})->with([30, null]);

test('pause denies bind verify free access and shared access checks until resumed', function () {
    installHostDesktopProtocol();
    $actor = testAdminActor();
    $user = User::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id]);
    $devices = app(LicensedDevices::class);
    $devices->bind($user, $entitlement->product, ['value' => 'pc']);
    app(ManageEntitlements::class)->pause($entitlement, $actor, 'Vacation');

    expect(fn () => $devices->bind($user, $entitlement->product, ['value' => 'pc']))->toThrow(LicensingException::class, 'subscription paused');
    expect(fn () => $devices->verify($user, $entitlement->product, ['value' => 'pc']))->toThrow(LicensingException::class, 'subscription paused');
    expect(fn () => app(AccessPolicy::class)->authorize($user, $entitlement->product, CarbonImmutable::now()->addDay()))->toThrow(LicensingException::class, 'subscription paused');
    expect(fn () => app(AccessPolicy::class)->assertAllowed($user, $entitlement->product))->toThrow(LicensingException::class, 'subscription paused');
    $this->postJson('/host/products/'.$entitlement->product->slug.'/login', desktopRequest($user))->assertForbidden()->assertJsonPath('error', 'subscription_paused');

    app(ManageEntitlements::class)->resume($entitlement, $actor, 'Returned');
    expect($devices->verify($user, $entitlement->product, ['value' => 'pc'])->access->source)->toBe('paid');
});

test('paused summaries expose balance and preserve restriction precedence', function (array $state, string $status) {
    $entitlement = Entitlement::factory()->paused(3601)->create(['user_id' => User::factory()->create()->id, ...$state]);

    $summary = app(CustomerLicenseSummary::class)->for($entitlement);
    $resource = (new EntitlementResource($entitlement))->toArray(Request::create('/'));

    expect($summary['status'])->toBe($status);
    expect($summary['remaining_seconds'])->toBe(3601);
    expect($resource['paused_at'])->toBe($summary['paused_at']);
    expect($resource['remaining_seconds'])->toBe(3601);
    expect($resource['lifetime'])->toBeFalse();
})->with([[[], 'paused'], [['status' => 'suspended'], 'suspended'], [['status' => 'revoked'], 'revoked']]);

test('clock mutations roll back on audit failure', function (string $operation) {
    $actor = testAdminActor();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    if ($operation === 'resume') {
        $entitlement->update(['paused_at' => now(), 'remaining_seconds' => 3600, 'expires_at' => null]);
    }
    $before = $entitlement->fresh()->getAttributes();
    Event::listen('eloquent.creating: '.AuditEvent::class, fn () => throw new RuntimeException('Audit failed'));
    $tools = app(ManageEntitlements::class);

    expect(fn () => $operation === 'extend' ? $tools->extend($entitlement, 1, $actor, 'Support') : $tools->{$operation}($entitlement, $actor, 'Support'))->toThrow(RuntimeException::class, 'Audit failed');

    expect($entitlement->fresh()->getAttributes())->toBe($before);
})->with(['extend', 'pause', 'resume']);

test('pause and host session cleanup roll back together when invalidation listener fails', function () {
    installHostDesktopProtocol();
    $actor = testAdminActor();
    $user = User::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id]);
    $this->postJson('/host/products/'.$entitlement->product->slug.'/login', desktopRequest($user))->assertOk();
    Event::listen(EntitlementAccessInvalidated::class, fn () => throw new RuntimeException('Cleanup failed'));

    expect(fn () => app(ManageEntitlements::class)->pause($entitlement, $actor, 'Vacation'))->toThrow(RuntimeException::class, 'Cleanup failed');

    expect($entitlement->fresh()->paused_at)->toBeNull();
    $this->assertDatabaseCount('host_desktop_sessions', 1);
    expect(AuditEvent::where('event', 'like', 'entitlement_%')->count())->toBe(0);
});

test('pause invalidates host desktop sessions', function () {
    installHostDesktopProtocol();
    $actor = testAdminActor();
    $user = User::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id]);
    $this->postJson('/host/products/'.$entitlement->product->slug.'/login', desktopRequest($user))->assertOk();

    app(ManageEntitlements::class)->pause($entitlement, $actor, 'Vacation');

    $this->assertDatabaseCount('host_desktop_sessions', 0);
});
