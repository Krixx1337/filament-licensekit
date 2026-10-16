<?php

use LicenseKit\Core\Actions\ManagementOperations;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\ApiClient;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Tests\Integration\Fixtures\User;

test('simultaneous management retries create one batch', function (string $operation, string $table) {
    $plan = LicensePlan::factory()->create();
    $client = ApiClient::factory()->create(['all_products' => true, 'permissions' => ['licenses:create']]);
    $token = $client->createToken('licensekit:api', ['licensekit:api', ...$client->permissions], now()->addHour())->plainTextToken;
    $results = $this->race([$operation, $operation], ['plan_id' => $plan->id, 'token' => $token]);
    expect($results[0])->toBe($results[1]);
    $this->assertDatabaseCount($table, 1);
    $this->assertDatabaseCount('licensekit_license_keys', 2);
})->with([
    ['management-issue', 'licensekit_batches'],
]);

test('credential revocation serializes against management mutation in either transaction order', function (string $operation, bool $revokeFirst) {
    $plan = LicensePlan::factory()->create();
    $actor = User::factory()->create();
    $client = ApiClient::factory()->create(['all_products' => true, 'permissions' => ['licenses:create']]);
    $token = $client->createToken('licensekit:api', ['licensekit:api', ...$client->permissions], now()->addHour())->plainTextToken;
    $results = $this->race(['management-revoke', $operation], ['plan_id' => $plan->id, 'client_id' => $client->id, 'actor_id' => $actor->id, 'token' => $token], $revokeFirst ? 'management-revoke' : $operation);
    expect($client->tokens()->count())->toBe(0);
    if ($revokeFirst) {
        expect($results[1])->toBe('invalid_token');
        $this->assertDatabaseCount('licensekit_license_keys', 0);
    } else {
        expect($results[1])->toBeInt();
        $this->assertDatabaseCount('licensekit_license_keys', 2);
    }
})->with(['management-issue'])->with([true, false]);

test('reseller retirement and suspension serialize against issuance in either order', function (string $operation, bool $disableFirst) {
    $plan = LicensePlan::factory()->create();
    $actor = User::factory()->create();
    $owner = User::factory()->create();
    $client = ApiClient::factory()->create(['owner_user_id' => $owner->id, 'all_products' => true, 'permissions' => ['licenses:create']]);
    $token = $client->createToken('licensekit:api', ['licensekit:api', 'licenses:create'], now()->addHour())->plainTextToken;
    $results = $this->race([$operation, 'management-issue'], ['plan_id' => $plan->id, 'client_id' => $client->id,
        'actor_id' => $actor->id, 'user_id' => $owner->id, 'token' => $token], $disableFirst ? $operation : 'management-issue');
    if ($disableFirst) {
        expect($results[1])->toBe('invalid_token');
        $this->assertDatabaseCount('licensekit_batches', 0);
        $this->assertDatabaseCount('licensekit_license_keys', 0);
    } else {
        expect($results[1])->toBeInt();
        $this->assertDatabaseCount('licensekit_batches', 1);
        $this->assertDatabaseCount('licensekit_license_keys', 2);
    }
    expect(fn () => app(ManagementOperations::class)->create($token, $plan->id, 1, 'after'))
        ->toThrow(LicensingException::class);
    if ($operation === 'management-retire') {
        expect($client->fresh()->retired_at)->not->toBeNull()->and($client->tokens()->count())->toBe(0);
    } else {
        expect($owner->fresh()->is_suspended)->toBeTrue();
    }
})->with(['management-retire', 'reseller-suspend'])->with([true, false]);
