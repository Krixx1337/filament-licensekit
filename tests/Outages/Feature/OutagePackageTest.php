<?php

use Illuminate\Support\Facades\Event;
use LicenseKit\Core\Actions\AccessPolicy;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Events\EntitlementGranted;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Modules\Outages\Actions\ManageOutages;
use LicenseKit\Modules\Outages\Models\Outage;
use LicenseKit\Tests\Outages\Fixtures\User;

test('start replay preserves original request after Stop and changed inputs conflict', function () {
    $admin = outageAdmin();
    $product = Product::factory()->create();
    $outage = app(ManageOutages::class)->start($product, 'incident', 'Maintenance', $admin);
    app(ManageOutages::class)->stop($outage, false, $admin);
    expect(app(ManageOutages::class)->start($product, 'incident', 'Maintenance', $admin)->id)->toBe($outage->id);
    expect(fn () => app(ManageOutages::class)->start($product, 'incident', 'Other', $admin))->toThrow(LicensingException::class, 'operation conflict');
    expect(Outage::count())->toBe(1);
});

test('grant listener failure rolls back key consumption and compensation ledger', function () {
    $admin = outageAdmin();
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    $outage = app(ManageOutages::class)->start($plan->product, 'incident', 'Maintenance', $admin);
    $key = app(CreateBatch::class)->create($plan, 1, 'purchase', 'fixture')->keys->first();
    Event::listen(EntitlementGranted::class, fn () => throw new RuntimeException('listener failed'));
    expect(fn () => app(ClaimLicense::class)->claim($user, $key->key))->toThrow(RuntimeException::class, 'listener failed');
    expect($key->fresh()->claimed_at)->toBeNull();
    $this->assertDatabaseCount('licensekit_entitlements', 0);
    $this->assertDatabaseCount('licensekit_compensation_entries', 0);
});

test('separate products may have incidents while stored blocking survives module disable', function () {
    $admin = outageAdmin();
    $first = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    $second = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    app(ManageOutages::class)->start($first->product, 'first', 'Maintenance', $admin, true);
    app(ManageOutages::class)->start($second->product, 'second', 'Maintenance', $admin, false);
    config(['licensekit.modules.outages' => false]);
    expect(fn () => app(AccessPolicy::class)->authorize($first->user, $first->product))->toThrow(LicensingException::class, 'service unavailable');
    expect(app(AccessPolicy::class)->authorize($second->user, $second->product)->source)->toBe('paid');
});
