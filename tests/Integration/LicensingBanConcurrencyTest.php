<?php

use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\AuditEvent;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Tests\Core\Fixtures\ClientSessions;
use LicenseKit\Tests\Integration\Fixtures\User;

test('MySQL committed ban prevents usable sessions in either transaction order', function (string $operation, bool $banFirst) {
    $this->freezeTime();
    $actor = User::factory()->create();
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    $key = app(CreateBatch::class)->create($plan, 1, 'sale', 'test')->keys->first();
    $hwid = ['value' => 'pc'];
    $token = null;
    if ($operation !== 'claim') {
        Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $plan->product_id]);
    }
    if ($operation === 'heartbeat') {
        $token = app(ClientSessions::class)->login($plan->product, $user->email, 'password', $hwid)['token'];
    }
    $results = $this->race(['ban', $operation], ['actor_id' => $actor->id, 'user_id' => $user->id, 'product_id' => $plan->product_id, 'token' => $token, 'key' => $key->key], $banFirst ? 'ban' : $operation);
    expect($results[0])->toBeInt();
    if ($banFirst) {
        expect($results[1])->toBe($operation === 'heartbeat' ? 'invalid_token' : 'license_banned');
    } elseif ($operation === 'claim') {
        expect($results[1])->toBeInt();
    } else {
        expect($results[1])->toBeString()->not->toBe('license_banned')->not->toBe('invalid_token');
    }
    $this->assertDatabaseCount('licensekit_licensing_bans', 1);
    $this->assertDatabaseCount('client_sessions', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    expect(fn () => app(ClientSessions::class)->login($plan->product, $user->email, 'password', $hwid))->toThrow(LicensingException::class, 'license banned');
    if ($token !== null) {
        expect(fn () => app(ClientSessions::class)->heartbeat($plan->product, $token, $hwid))->toThrow(LicensingException::class, 'invalid token');
    }
    if ($operation === 'claim') {
        expect($key->fresh()->claimed_at === null)->toBe($banFirst);
        $this->assertDatabaseCount('licensekit_entitlements', $banFirst ? 0 : 1);
    }
})->with([
    ['login', true], ['login', false], ['heartbeat', true], ['heartbeat', false], ['claim', true], ['claim', false],
]);

test('MySQL concurrent duplicate bans retain one history record and audit', function () {
    $actor = User::factory()->create();
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    $results = $this->race(['ban', 'ban'], ['actor_id' => $actor->id, 'user_id' => $user->id, 'product_id' => $plan->product_id]);
    expect($results)->toContain('ban_already_active');
    expect(count(array_filter($results, 'is_int')))->toBe(1);
    $this->assertDatabaseCount('licensekit_licensing_bans', 1);
    $this->assertDatabaseHas('licensekit_audit_events', ['event' => 'licensing_ban_created']);
    expect(AuditEvent::where('event', 'licensing_ban_created')->count())->toBe(1);
});
