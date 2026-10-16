<?php

use Illuminate\Support\Facades\Event;
use LicenseKit\Core\Actions\ManageApiConnections;
use LicenseKit\Core\Models\ActivityEntry;
use LicenseKit\Core\Models\ApiConnection;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Tests\Core\Fixtures\User;

beforeEach(function () {
    $this->management = true;
    $this->refreshApplication();
    app('migrator')->path(__DIR__.'/../Fixtures/migrations');
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
});

function connectionToken(array $attributes = []): array
{
    $connection = ApiConnection::factory()->create($attributes);
    $token = app(ManageApiConnections::class)->regenerate($connection, User::factory()->create());

    return [$connection, $token];
}

test('connection creates keys and replays request without duplicate issuance', function () {
    [$connection, $token] = connectionToken();
    $plan = LicensePlan::factory()->create();
    $data = ['plan_id' => $plan->id, 'quantity' => 2, 'reference' => 'order-123'];
    $url = route('licensekit.management.batches.store');
    $first = $this->withToken($token)->postJson($url, $data)->assertCreated()->json('data');
    $second = $this->withToken($token)->postJson($url, $data)->assertOk()->json('data');
    expect($first['id'])->toBe($second['id']);
    expect($first['keys'])->toHaveCount(2);
    $this->assertDatabaseCount('licensekit_batches', 1);
    $this->assertDatabaseCount('licensekit_keys', 2);
    expect($connection->fresh()->token_hash)->toBe(hash('sha256', $token));
    expect($connection->fresh()->toArray())->not->toHaveKey('token_hash');
    expect(ActivityEntry::all()->toJson())->not->toContain($token);
});

test('connections see only their own batches even after allowlist changes', function () {
    [$connection, $token] = connectionToken();
    [, $otherToken] = connectionToken();
    $plan = LicensePlan::factory()->create();
    $batch = $this->withToken($token)->postJson(route('licensekit.management.batches.store'), ['plan_id' => $plan->id, 'quantity' => 1, 'reference' => 'own'])->assertCreated()->json('data.id');
    $connection->update(['allowed_product_ids' => []]);
    $this->withToken($token)->getJson(route('licensekit.management.batches.show', $batch))->assertOk();
    $this->withToken($otherToken)->getJson(route('licensekit.management.batches.show', $batch))->assertNotFound();
    $this->withToken($otherToken)->getJson(route('licensekit.management.batches.keys', $batch))->assertNotFound();
    $this->withToken($otherToken)->getJson(route('licensekit.management.batches.index'))->assertJsonCount(0, 'data');
});

test('connection product restrictions quantity cap and input validation prevent issuance', function (string $failure, int $status) {
    $plan = LicensePlan::factory()->create();
    [$connection, $token] = connectionToken(['allowed_product_ids' => $failure === 'product' ? [] : [$plan->product_id], 'max_per_request' => 1]);
    $data = ['plan_id' => $plan->id, 'quantity' => 1, 'reference' => 'order'];
    if ($failure === 'quantity') {
        $data['quantity'] = 2;
    }
    if ($failure === 'validation') {
        $data['reference'] = '';
    }
    $this->withToken($token)->postJson(route('licensekit.management.batches.store'), $data)->assertStatus($status);
    $this->assertDatabaseCount('licensekit_batches', 0);
})->with([['product', 404], ['quantity', 403], ['validation', 422]]);

test('regeneration and disabling reject old tokens without affecting other connections', function () {
    $actor = User::factory()->create();
    [$connection, $old] = connectionToken();
    [, $other] = connectionToken();
    $new = app(ManageApiConnections::class)->regenerate($connection, $actor);
    $url = route('licensekit.management.products.index');
    $this->withToken($old)->getJson($url)->assertUnauthorized();
    $this->withToken($new)->getJson($url)->assertOk();
    $connection->update(['is_active' => false]);
    $this->withToken($new)->getJson($url)->assertUnauthorized();
    $this->withToken($other)->getJson($url)->assertOk();
});

test('conflicting request and invalid pagination are explicit errors', function () {
    [, $token] = connectionToken();
    $plan = LicensePlan::factory()->create();
    $data = ['plan_id' => $plan->id, 'quantity' => 1, 'reference' => 'order'];
    $this->withToken($token)->postJson(route('licensekit.management.batches.store'), $data)->assertCreated();
    $this->withToken($token)->postJson(route('licensekit.management.batches.store'), [...$data, 'quantity' => 2])->assertConflict()->assertJsonPath('error', 'batch_conflict');
    $this->withToken($token)->getJson(route('licensekit.management.batches.index', ['per_page' => 101]))->assertUnprocessable();
});

test('connection audit failure restores token hash', function () {
    [$connection, $token] = connectionToken();
    Event::listen('eloquent.creating: '.ActivityEntry::class, fn () => throw new RuntimeException('audit failed'));
    expect(fn () => app(ManageApiConnections::class)->regenerate($connection, User::factory()->create()))->toThrow(RuntimeException::class, 'audit failed');
    expect($connection->fresh()->token_hash)->toBe(hash('sha256', $token));
});
