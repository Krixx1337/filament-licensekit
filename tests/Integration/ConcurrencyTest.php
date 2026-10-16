<?php

use LicenseKit\Core\Actions\Keys;
use LicenseKit\Core\Data\IssueContext;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Tests\Integration\Fixtures\User;

test('concurrent redemption protects keys and accumulates time on one license', function (bool $sameKey) {
    $this->freezeTime();
    $user = User::factory()->create();
    $batch = app(Keys::class)->issue(LicensePlan::factory()->create(), 2, new IssueContext);
    $results = $this->race(['redeem', 'redeem'], ['user_id' => $user->id, 'keys' => [$batch->keys[0]->key, $batch->keys[$sameKey ? 0 : 1]->key]]);
    expect(collect($results)->where('status', 'ok'))->toHaveCount($sameKey ? 1 : 2);
    if ($sameKey) {
        expect(collect($results)->where('error', 'key_already_used'))->toHaveCount(1);
    }
    expect(License::sole()->expires_at->toDateString())->toBe(now()->addDays($sameKey ? 30 : 60)->toDateString());
})->with([true, false]);

test('concurrent identical issuance returns one complete batch under host snapshots', function () {
    $plan = LicensePlan::factory()->create();
    $results = $this->race(['issue', 'issue'], ['plan_id' => $plan->id, 'old_snapshot' => true]);
    expect($results[0]['status'])->toBe('ok')->and($results[1]['status'])->toBe('ok');
    expect($results[0]['id'])->toBe($results[1]['id']);
    expect($results[0]['keys'])->toBe($results[1]['keys'])->toHaveCount(2);
    $this->assertDatabaseCount('licensekit_batches', 1);
    $this->assertDatabaseCount('licensekit_keys', 2);
});
