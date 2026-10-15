<?php

use Carbon\CarbonImmutable;
use LicenseKit\Core\Actions\ManageEntitlements;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Modules\Outages\Actions\ManageOutages;
use LicenseKit\Modules\Outages\Models\CompensationEntry;
use LicenseKit\Tests\Outages\Fixtures\User;

test('individual clock changes wait for product operations even with module disabled', function (string $change, string $state, bool $enabled) {
    $actor = outageAdmin();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    if ($change === 'resume') {
        $entitlement->update(['paused_at' => now(), 'remaining_seconds' => 3600, 'expires_at' => null]);
    }
    $outages = app(ManageOutages::class);
    if ($state === 'outage') {
        $outages->start($entitlement->product, 'outage', 'Deployment', $actor);
    } else {
        $operation = $outages->addTime($entitlement->product, 3600, CarbonImmutable::now(), 'credit', 'Support', $actor);
        $operation->update(['status' => $state]);
    }
    config(['licensekit.modules.outages' => $enabled]);
    $before = $entitlement->fresh()->getAttributes();
    $tools = app(ManageEntitlements::class);

    expect(fn () => $change === 'extend' ? $tools->extend($entitlement, 3600, $actor, 'Support') : $tools->{$change}($entitlement, $actor, 'Support'))->toThrow(LicensingException::class, 'operation conflict');

    expect($entitlement->fresh()->getAttributes())->toBe($before);
})->with(['extend', 'pause', 'resume'])->with(['outage', 'pending', 'processing', 'failed'])->with([true, false]);

test('individual extensions work after compensation completes or cancels', function (bool $complete) {
    $this->freezeTime();
    $actor = outageAdmin();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    $outages = app(ManageOutages::class);
    $operation = $outages->addTime($entitlement->product, 3600, CarbonImmutable::now(), 'credit', 'Support', $actor);
    if ($complete) {
        applyOperation($operation);
    } else {
        $outages->cancel($operation, $actor);
    }
    $before = $entitlement->fresh()->expires_at;

    app(ManageEntitlements::class)->extend($entitlement, 3600, $actor, 'Support');

    expect($entitlement->fresh()->expires_at->equalTo($before->addHour()))->toBeTrue();
})->with([true, false]);

test('stopped outage blocks individual changes until compensation ends', function () {
    $actor = outageAdmin();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    $outages = app(ManageOutages::class);
    $outage = $outages->start($entitlement->product, 'outage', 'Deployment', $actor);
    $outages->stop($outage, true, $actor);

    expect(fn () => app(ManageEntitlements::class)->pause($entitlement, $actor, 'Vacation'))->toThrow(LicensingException::class, 'operation conflict');

    applyOperation($outage->operation);
    app(ManageEntitlements::class)->pause($entitlement, $actor, 'Vacation');
    expect($entitlement->fresh()->paused_at)->not->toBeNull();
});

test('paused licenses stay out of outage and historical manual credit snapshots', function (string $kind) {
    $this->freezeTime();
    $actor = outageAdmin();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id, 'created_at' => now()->subDays(2)]);
    app(ManageEntitlements::class)->pause($entitlement, $actor, 'Vacation');
    $outages = app(ManageOutages::class);

    $operation = $kind === 'outage'
        ? $outages->start($entitlement->product, 'outage', 'Deployment', $actor)->operation
        : $outages->addTime($entitlement->product, 3600, CarbonImmutable::now()->subDay(), 'credit', 'Support', $actor);

    expect($operation->target_count)->toBe(0);
    $this->assertDatabaseCount('licensekit_compensation_entries', 0);
    expect($entitlement->fresh()->remaining_seconds)->toBe(2592000);
})->with(['outage', 'manual']);

test('compensation worker skips paused records even if snapshot exists', function () {
    $this->freezeTime();
    $actor = outageAdmin();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    $operation = app(ManageOutages::class)->addTime($entitlement->product, 3600, CarbonImmutable::now(), 'credit', 'Support', $actor);
    $entitlement->update(['paused_at' => now(), 'remaining_seconds' => 3600, 'expires_at' => null]);

    applyOperation($operation);

    expect($operation->fresh()->skipped_count)->toBe(1);
    expect(CompensationEntry::where('operation_id', $operation->id)->first()->result)->toBe('skipped');
    expect($entitlement->fresh()->remaining_seconds)->toBe(3600);
    expect($entitlement->fresh()->expires_at)->toBeNull();
});

test('another product operation does not prevent individual changes', function () {
    $actor = outageAdmin();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    $other = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    app(ManageOutages::class)->start($other->product, 'outage', 'Deployment', $actor);

    app(ManageEntitlements::class)->pause($entitlement, $actor, 'Vacation');

    expect($entitlement->fresh()->paused_at)->not->toBeNull();
});
