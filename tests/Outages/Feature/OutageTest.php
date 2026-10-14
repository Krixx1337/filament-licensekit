<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use LicenseKit\Core\Actions\AccessPolicy;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\AuditEvent;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Modules\Outages\Actions\ManageOutages;
use LicenseKit\Modules\Outages\Jobs\ApplyCompensation;
use LicenseKit\Modules\Outages\Models\CompensationEntry;
use LicenseKit\Support\OutageState;
use LicenseKit\Tests\Outages\Fixtures\User;

test('blocking choice is independent of compensation and duplicate delivery never credits twice', function (bool $block, bool $compensate) {
    Queue::fake([ApplyCompensation::class]);
    $this->travelTo(now()->startOfSecond());
    $admin = outageAdmin();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id, 'expires_at' => now()->addMinutes(30)]);
    $original = $entitlement->expires_at;
    $outage = app(ManageOutages::class)->start($entitlement->product, 'incident', 'Maintenance', $admin, $block);
    expect($entitlement->fresh()->expires_at->equalTo($original))->toBeTrue();
    if ($block) {
        expect(fn () => app(AccessPolicy::class)->authorize($entitlement->user, $entitlement->product))->toThrow(LicensingException::class, 'service unavailable');
    } else {
        expect(app(AccessPolicy::class)->authorize($entitlement->user, $entitlement->product)->source)->toBe('paid');
    }
    $this->travel(6)->hours();
    app(ManageOutages::class)->stop($outage, $compensate, $admin);
    if ($compensate) {
        applyOperation($outage->operation);
        applyOperation($outage->operation);
        expect($entitlement->fresh()->expires_at->equalTo(now()->addMinutes(30)))->toBeTrue();
        expect($outage->operation->fresh()->credited_count)->toBe(1);
    } else {
        expect($entitlement->fresh()->expires_at->equalTo($original))->toBeTrue();
    }
    expect($outage->fresh()->ended_at)->not->toBeNull();
    if ($compensate) {
        Queue::assertPushed(ApplyCompensation::class, fn ($job): bool => $job->operationId === $outage->operation->id);
    } else {
        Queue::assertNothingPushed();
    }
})->with([[false, false], [false, true], [true, false], [true, true]]);

test('nonblocking settlement uses Stop time while blocking settlement preserves processing delay', function (bool $block) {
    $this->travelTo(now()->startOfSecond());
    $admin = outageAdmin();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id, 'expires_at' => now()->addMinutes(30)]);
    $outage = app(ManageOutages::class)->start($entitlement->product, 'incident', 'Maintenance', $admin, $block);
    $this->travel(6)->hours();
    app(ManageOutages::class)->stop($outage, true, $admin);
    $stop = CarbonImmutable::now();
    $this->travel(10)->minutes();
    applyOperation($outage->operation);
    expect($entitlement->fresh()->expires_at->equalTo(($block ? CarbonImmutable::now() : $stop)->addMinutes(30)))->toBeTrue();
})->with([false, true]);

test('late purchase and expired-before-start renewal retain only purchased duration', function (bool $previouslyExpired) {
    $this->travelTo(now()->startOfSecond());
    $admin = outageAdmin();
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    if ($previouslyExpired) {
        Entitlement::factory()->expired()->create(['user_id' => $user->id, 'product_id' => $plan->product_id]);
    }
    $outage = app(ManageOutages::class)->start($plan->product, 'incident', 'Maintenance', $admin);
    $this->travel(4)->days();
    $key = app(CreateBatch::class)->create($plan, 1, 'purchase', 'fixture')->keys->first();
    $entitlement = app(ClaimLicense::class)->claim($user, $key->key);
    $this->travel(1)->days();
    app(ManageOutages::class)->stop($outage, true, $admin);
    applyOperation($outage->operation);
    expect($entitlement->fresh()->expires_at->equalTo(now()->addDays(30)))->toBeTrue();
})->with([false, true]);

test('manual credit adds to old expiry and snapshots current cutoff eligibility', function () {
    $this->travelTo(now()->startOfSecond());
    $admin = outageAdmin();
    $product = Product::factory()->create();
    $eligible = Entitlement::factory()->create(['user_id' => User::factory()->create()->id, 'product_id' => $product->id, 'created_at' => now()->subDays(3), 'expires_at' => now()->subHours(12)]);
    Entitlement::factory()->create(['user_id' => User::factory()->create()->id, 'product_id' => $product->id, 'created_at' => now(), 'expires_at' => now()->addDay()]);
    $cutoff = CarbonImmutable::now()->subDay();
    expect(app(ManageOutages::class)->preview($product, $cutoff, $admin))->toBe(1);
    $operation = app(ManageOutages::class)->addTime($product, 86400, $cutoff, 'credit', 'Goodwill', $admin);
    applyOperation($operation);
    expect($eligible->fresh()->expires_at->equalTo(now()->addHours(12)))->toBeTrue();
    expect($operation->fresh()->credited_count)->toBe(1);
    $replay = app(ManageOutages::class)->addTime($product, 86400, $cutoff, 'credit', 'Goodwill', $admin);
    expect($replay->id)->toBe($operation->id);
    applyOperation($replay);
    expect($eligible->fresh()->expires_at->equalTo(now()->addHours(12)))->toBeTrue();
    expect(fn () => app(ManageOutages::class)->addTime($product, 3600, $cutoff, 'credit', 'Goodwill', $admin))->toThrow(LicensingException::class, 'operation conflict');
});

test('application skips lifetime revoked and detached targets without changing their restrictions', function (string $change) {
    $this->travelTo(now()->startOfSecond());
    $admin = outageAdmin();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    $outage = app(ManageOutages::class)->start($entitlement->product, 'incident', 'Maintenance', $admin, true);
    match ($change) {
        'lifetime' => $entitlement->update(['is_lifetime' => true, 'expires_at' => null]),
        'revoked' => $entitlement->update(['is_revoked' => true]),
        'deleted' => $entitlement->user->delete(),
    };
    app(ManageOutages::class)->stop($outage, true, $admin);
    applyOperation($outage->operation);
    expect($outage->operation->fresh()->skipped_count)->toBe(1)->and($outage->operation->fresh()->credited_count)->toBe(0);
    expect(CompensationEntry::first()->result)->toBe('skipped');
})->with(['lifetime', 'revoked', 'deleted']);

test('audit failure rolls back all credits and cancellation prevents later worker application', function () {
    $this->travelTo(now()->startOfSecond());
    $admin = outageAdmin();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    $original = $entitlement->expires_at;
    $outage = app(ManageOutages::class)->start($entitlement->product, 'incident', 'Maintenance', $admin, true);
    $this->travel(6)->hours();
    app(ManageOutages::class)->stop($outage, true, $admin);
    Event::listen('eloquent.creating: '.AuditEvent::class, function (AuditEvent $audit): void {
        if ($audit->event === 'compensation_completed') {
            throw new RuntimeException('audit failed');
        }
    });
    expect(fn () => applyOperation($outage->operation))->toThrow(RuntimeException::class, 'audit failed');
    expect($entitlement->fresh()->expires_at->equalTo($original))->toBeTrue();
    expect(CompensationEntry::first()->result)->toBeNull();
    expect($outage->operation->fresh()->status)->toBe('failed');
    app(ManageOutages::class)->cancel($outage->operation, $admin);
    applyOperation($outage->operation);
    expect($entitlement->fresh()->expires_at->equalTo($original))->toBeTrue();
    expect($outage->fresh()->ended_at)->not->toBeNull();
});

test('overlap manual conflicts disabled module and pending claims enforce product boundaries', function () {
    $this->travelTo(now()->startOfSecond());
    $admin = outageAdmin();
    $plan = LicensePlan::factory()->create();
    $user = User::factory()->create();
    $key = app(CreateBatch::class)->create($plan, 1, 'purchase', 'fixture')->keys->first();
    $outage = app(ManageOutages::class)->start($plan->product, 'incident', 'Maintenance', $admin, false);
    expect(fn () => app(ManageOutages::class)->start($plan->product, 'second', 'Maintenance', $admin))->toThrow(LicensingException::class, 'operation conflict');
    expect(fn () => app(ManageOutages::class)->addTime($plan->product, 86400, CarbonImmutable::now(), 'credit', 'Goodwill', $admin))->toThrow(LicensingException::class, 'operation conflict');
    app(ManageOutages::class)->stop($outage, true, $admin);
    config(['licensekit.modules.outages' => false]);
    expect(fn () => app(ClaimLicense::class)->claim($user, $key->key))->toThrow(LicensingException::class, 'outage processing');
    expect($key->fresh()->claimed_at)->toBeNull();
    config(['licensekit.modules.outages' => true]);
    app(ManageOutages::class)->cancel($outage->operation, $admin);
    app(ClaimLicense::class)->claim($user, $key->key);
    expect($key->fresh()->claimed_at)->not->toBeNull();
});

test('public admin operations deny unprivileged actors without middleware or panel', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    expect(fn () => app(ManageOutages::class)->start($product, 'incident', 'Maintenance', $user))->toThrow(LicensingException::class, 'outage forbidden');
    expect(fn () => app(ManageOutages::class)->addTime($product, 86400, CarbonImmutable::now(), 'credit', 'Goodwill', $user))->toThrow(LicensingException::class, 'outage forbidden');
    $this->assertDatabaseCount('licensekit_outages', 0);
    $this->assertDatabaseCount('licensekit_compensation_operations', 0);
});

test('compensation preserves UTC purchased seconds in a host with another timezone', function () {
    $previous = date_default_timezone_get();
    try {
        config(['app.timezone' => 'America/New_York']);
        date_default_timezone_set('America/New_York');
        $this->travelTo(CarbonImmutable::parse('2026-10-16T12:00:00Z'));
        $admin = outageAdmin();
        $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id, 'expires_at' => now()->addMinutes(30)]);
        $outage = app(ManageOutages::class)->start($entitlement->product, 'timezone', 'Maintenance', $admin, true);
        expect($outage->operation->target_count)->toBe(1);
        $this->travel(6)->hours();
        app(ManageOutages::class)->stop($outage, true, $admin);
        applyOperation($outage->operation);
        expect($entitlement->fresh()->expires_at->toIso8601String())->toBe('2026-10-16T18:30:00+00:00');
    } finally {
        date_default_timezone_set($previous);
    }
});

test('queue dispatch failure is visible recoverable and preserves claim restriction until cancellation', function () {
    $this->travelTo(now()->startOfSecond());
    $admin = outageAdmin();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    $outage = app(ManageOutages::class)->start($entitlement->product, 'dispatch', 'Maintenance', $admin, true);
    Exceptions::fake();
    Bus::partialMock()->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unreachable'));
    app(ManageOutages::class)->stop($outage, true, $admin);
    expect($outage->operation->fresh()->status)->toBe('failed');
    expect($outage->operation->fresh()->failure)->toBe('dispatch_failed');
    expect(fn () => app(OutageState::class)->assertClaim($entitlement->product_id))->toThrow(LicensingException::class, 'outage processing');
    Exceptions::assertReported(RuntimeException::class);
    app(ManageOutages::class)->cancel($outage->operation, $admin);
    app(OutageState::class)->assertClaim($entitlement->product_id);
    expect($outage->fresh()->ended_at)->not->toBeNull();
});

test('audited retry reuses the same ledger and distinct confirmed manual credits remain additive', function () {
    $this->travelTo(now()->startOfSecond());
    $admin = outageAdmin();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    $old = $entitlement->expires_at;
    $operation = app(ManageOutages::class)->addTime($entitlement->product, 3600, now()->toImmutable(), 'first', 'Goodwill', $admin);
    $operation->update(['status' => 'failed']);
    Queue::fake([ApplyCompensation::class]);
    app(ManageOutages::class)->retry($operation, $admin);
    Queue::assertPushed(ApplyCompensation::class, fn ($job): bool => $job->operationId === $operation->id);
    applyOperation($operation);
    $second = app(ManageOutages::class)->addTime($entitlement->product, 3600, now()->toImmutable(), 'second', 'Goodwill', $admin);
    applyOperation($second);
    expect($entitlement->fresh()->expires_at->equalTo($old->addHours(2)))->toBeTrue();
    $this->assertDatabaseHas('licensekit_audit_events', ['event' => 'compensation_retried', 'actor' => 'user:'.$admin->id]);
});
