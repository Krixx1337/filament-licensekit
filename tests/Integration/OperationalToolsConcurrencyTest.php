<?php

use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Tests\Integration\Fixtures\User;

test('concurrent creation on different plans preserves batch ownership', function (bool $sameProduct) {
    $plan = LicensePlan::factory()->create();
    $second = LicensePlan::factory()->create($sameProduct ? ['product_id' => $plan->product_id] : []);
    $results = $this->race(['create-plan', 'create-plan'], ['plan_ids' => [$plan->id, $second->id]]);
    expect($results[0])->not->toBe($results[1]);
    $this->assertDatabaseCount('licensekit_batches', 2);
    $this->assertDatabaseCount('licensekit_license_keys', 2);
    foreach (Batch::with('keys')->get() as $batch) {
        expect($batch->keys[0]->license_plan_id)->toBe($batch->license_plan_id);
    }
})->with([true, false]);

test('batch revocation serializes against customer claims in both transaction orders', function (bool $revokeFirst) {
    $actor = integrationAdmin();
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    $batch = app(CreateBatch::class)->createFor($actor, $plan, 1, 'batch');

    $results = $this->race(['batch-revoke', 'claim'], ['actor_id' => $actor->id, 'user_id' => $user->id, 'product_id' => $plan->product_id, 'batch_id' => $batch->id, 'key' => $batch->keys->first()->key], $revokeFirst ? 'batch-revoke' : 'claim');

    $key = LicenseKey::firstOrFail();
    if ($revokeFirst) {
        expect($results)->toBe([1, 'invalid_key']);
        expect($key->claimed_at)->toBeNull()->and($key->revoked_at)->not->toBeNull();
        $this->assertDatabaseCount('licensekit_entitlements', 0);
    } else {
        expect($results[0])->toBe(0);
        expect($results[1])->toBeInt();
        expect($key->claimed_at)->not->toBeNull()->and($key->revoked_at)->toBeNull();
        $this->assertDatabaseCount('licensekit_entitlements', 1);
    }
})->with([true, false]);

test('same issuer reference across different products returns conflict without duplicate keys', function () {
    $first = LicensePlan::factory()->create();
    $second = LicensePlan::factory()->create();
    $results = $this->race(['create-plan-conflict', 'create-plan-conflict'], ['plan_ids' => [$first->id, $second->id]]);
    expect($results)->toContain('batch_conflict');
    expect(count(array_filter($results, 'is_int')))->toBe(1);
    $this->assertDatabaseCount('licensekit_batches', 1);
    $this->assertDatabaseCount('licensekit_license_keys', 1);
});
