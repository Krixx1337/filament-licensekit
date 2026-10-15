<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Actions\Audit;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Models\DeviceBinding;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Modules\Outages\Actions\ManageOutages;
use LicenseKit\Modules\Outages\Jobs\ApplyCompensation;
use LicenseKit\Tests\Core\Fixtures\ClientSessions;
use LicenseKit\Tests\Outages\Fixtures\User;

test('MySQL individual extension and pause preserve every granted second', function (string $first) {
    $actor = outageAdmin();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    $expiry = $entitlement->expires_at;

    $results = $this->race(['individual-extend', 'individual-pause'], ['entitlement_id' => $entitlement->id, 'product_id' => $entitlement->product_id, 'user_id' => $entitlement->user_id, 'actor_id' => $actor->id], $first);

    expect($results)->toBe([$entitlement->id, $entitlement->id]);
    $entitlement->refresh();
    expect($entitlement->expires_at)->toBeNull();
    expect($entitlement->remaining_seconds)->toBe((int) $entitlement->paused_at->diffInSeconds($expiry, false) + 3600);
})->with(['individual-extend', 'individual-pause']);

test('MySQL claim and pause serialize without consuming a key while paused', function (string $first) {
    $actor = outageAdmin();
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->create(['duration_days' => 1]);
    $key = app(CreateBatch::class)->create($plan, 1, 'sale', 'test')->keys->first();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $plan->product_id]);
    $expiry = $entitlement->expires_at;

    $results = $this->race(['claim', 'individual-pause'], ['entitlement_id' => $entitlement->id, 'product_id' => $entitlement->product_id, 'user_id' => $user->id, 'actor_id' => $actor->id, 'key' => $key->key], $first);

    expect($results)->toBe([$first === 'claim' ? $entitlement->id : 'subscription_paused', $entitlement->id]);
    $entitlement->refresh();
    expect($key->fresh()->claimed_at !== null)->toBe($first === 'claim');
    expect($entitlement->remaining_seconds)->toBe((int) $entitlement->paused_at->diffInSeconds($expiry, false) + ($first === 'claim' ? 86400 : 0));
})->with(['claim', 'individual-pause']);

test('MySQL outage start and individual pause cannot overlap clocks', function (string $first) {
    $actor = outageAdmin();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);

    $results = $this->race(['start', 'individual-pause'], ['entitlement_id' => $entitlement->id, 'product_id' => $entitlement->product_id, 'actor_id' => $actor->id], $first);

    expect($results[0])->toBeInt();
    expect($results[1])->toBe($first === 'start' ? 'operation_conflict' : $entitlement->id);
    expect($entitlement->fresh()->paused_at !== null)->toBe($first === 'individual-pause');
    $this->assertDatabaseCount('licensekit_compensation_entries', $first === 'start' ? 1 : 0);
})->with(['start', 'individual-pause']);

test('MySQL competing users cannot claim the same key', function () {
    $users = User::factory()->count(2)->create();
    $plan = LicensePlan::factory()->create();
    $key = app(CreateBatch::class)->create($plan, 1, 'sale', 'test')->keys->first();
    $results = $this->race(['competing-claim', 'competing-claim'], ['user_ids' => $users->pluck('id')->all(), 'key' => $key->key]);
    expect($results)->toContain('key_already_claimed');
    $this->assertDatabaseCount('licensekit_entitlements', 1);
    expect(LicenseKey::whereNotNull('claimed_at')->count())->toBe(1);
});
test('MySQL distinct requests create independent batches', function () {
    $plan = LicensePlan::factory()->create();
    $results = $this->race(['create-distinct', 'create-distinct'], ['plan_id' => $plan->id]);
    expect($results[0])->not->toBe($results[1]);
    $this->assertDatabaseCount('licensekit_batches', 2);
    $this->assertDatabaseCount('licensekit_license_keys', 2);
});

test('MySQL concurrent issuance retries return same original batch', function () {
    $plan = LicensePlan::factory()->create();
    $results = $this->race(['issue', 'issue'], ['plan_id' => $plan->id]);
    expect($results[0])->toBe($results[1]);
    $this->assertDatabaseCount('licensekit_batches', 1);
    $this->assertDatabaseCount('licensekit_license_keys', 2);
});

test('MySQL conflicting quantities cannot fulfill the same reference twice', function () {
    $plan = LicensePlan::factory()->create();
    $results = $this->race(['issue', 'create-conflict'], ['plan_id' => $plan->id]);
    expect($results)->toContain('batch_conflict');
    expect(count(array_filter($results, 'is_int')))->toBe(1);
    $this->assertDatabaseCount('licensekit_batches', 1);
    expect(LicenseKey::count())->toBeIn([1, 2]);
});

test('MySQL duplicate starts create one product incident and one compensation snapshot', function () {
    $this->travelTo(now()->startOfSecond());
    $actor = User::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    $results = $this->race(['start', 'start'], ['product_id' => $entitlement->product_id, 'actor_id' => $actor->id]);
    expect($results[0])->toBe($results[1]);
    $this->assertDatabaseCount('licensekit_outages', 1);
    $this->assertDatabaseCount('licensekit_compensation_entries', 1);
    expect($entitlement->fresh()->expires_at->equalTo($entitlement->expires_at))->toBeTrue();
});

test('MySQL duplicate manual credits and workers apply exactly one credit', function () {
    $this->travelTo(now()->startOfSecond());
    $actor = User::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    $results = $this->race(['credit', 'credit'], ['product_id' => $entitlement->product_id, 'actor_id' => $actor->id]);
    expect($results[0])->toBe($results[1]);
    $this->race(['apply', 'apply'], ['operation_id' => $results[0]]);
    expect($entitlement->fresh()->expires_at->equalTo($entitlement->expires_at->addHour()))->toBeTrue();
    $this->assertDatabaseCount('licensekit_compensation_operations', 1);
});

test('lean v1 MySQL release benchmark meets predefined key and compensation budgets', function () {
    $this->travelTo(now()->startOfSecond());
    $actor = User::factory()->create();
    Gate::define('licensekit.manage', fn ($user): bool => $user->id === $actor->id);
    $plan = LicensePlan::factory()->create();
    $users = User::factory()->count(10000)->make();
    foreach ($users->chunk(250) as $chunk) {
        DB::table('users')->insert($chunk->map(fn ($user): array => [...$user->getAttributes(), 'created_at' => now(), 'updated_at' => now()])->all());
    }
    $rows = [];
    $ids = User::where('id', '!=', $actor->id)->pluck('id');
    foreach ($ids as $id) {
        $rows[] = [...Entitlement::factory()->make(['user_id' => $id, 'product_id' => $plan->product_id, 'expires_at' => now()->addDay()])->getAttributes(), 'created_at' => now(), 'updated_at' => now()];
    }
    foreach (array_chunk($rows, 250) as $chunk) {
        DB::table('licensekit_entitlements')->insert($chunk);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $generationStart = microtime(true);
    $batch = app(CreateBatch::class)->create($plan, 1000, 'benchmark-keys', 'fixture');
    $generationSeconds = microtime(true) - $generationStart;
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(count($queries))->toBeLessThan(30);
    expect(collect($queries)->filter(fn ($query): bool => str_contains($query['query'], 'exists') && str_contains($query['query'], 'licensekit_license_keys')))->toBeEmpty();
    $start = microtime(true);
    $outage = app(ManageOutages::class)->start($plan->product, 'benchmark', 'Benchmark', $actor);
    $snapshotSeconds = microtime(true) - $start;
    expect($outage->operation->target_count)->toBe(10000);
    expect($snapshotSeconds)->toBeLessThan(5.0);
    $this->travel(6)->hours();
    app(ManageOutages::class)->stop($outage, true, $actor);
    $start = microtime(true);
    (new ApplyCompensation($outage->operation->id))->handle(app(Audit::class));
    $applySeconds = microtime(true) - $start;
    expect($applySeconds)->toBeLessThan(5.0);
    expect($outage->operation->fresh()->credited_count)->toBe(10000);
    expect(Entitlement::where('expires_at', now()->addDay())->count())->toBe(10000);
    $manual = app(ManageOutages::class)->addTime($plan->product, 3600, now()->toImmutable(), 'benchmark-contention', 'Benchmark', $actor);
    $timings = $this->race(['apply', 'claim'], ['operation_id' => $manual->id, 'user_id' => $ids->first(), 'key' => $batch->keys->first()->key, 'product_id' => $plan->product_id, 'measure' => true], 'apply');
    expect($timings[1]['value'])->toBeInt();
    expect($timings[1]['seconds'])->toBeLessThan(5.0);
    fwrite(STDOUT, sprintf("\nBenchmark MySQL 8.4, 2 CPU/1 GiB: 1000 keys %.3fs/%d queries; 10000 snapshot %.3fs; settlement %.3fs; contending claim %.3fs.\n", $generationSeconds, count($queries), $snapshotSeconds, $applySeconds, $timings[1]['seconds']));
});

test('MySQL different products competing for global host-free binding admit only one PC', function () {
    config(['licensekit.device_binding.scope' => 'global']);
    $user = User::factory()->create();
    $products = Product::factory()->count(2)->create();
    $results = $this->race(['global-login', 'global-login'], ['binding_scope' => 'global', 'user_id' => $user->id, 'product_ids' => $products->pluck('id')->all(), 'free_until' => now()->addHour()->toIso8601String()]);
    expect($results)->toContain('hwid_mismatch');
    $this->assertDatabaseCount('licensekit_device_bindings', 1);
    $this->assertDatabaseCount('client_sessions', 1);
    $this->assertDatabaseCount('licensekit_entitlements', 0);
});

test('MySQL global reset and cross-product host-free heartbeat leave no usable session', function (string $firstOperation) {
    config(['licensekit.device_binding.scope' => 'global', 'host_client.free_until' => now()->addHour()->toIso8601String()]);
    $user = User::factory()->create();
    $products = Product::factory()->count(2)->create();
    $hwid = ['value' => 'pc'];
    app(ClientSessions::class)->login($products[0], $user->email, 'password', $hwid);
    $token = app(ClientSessions::class)->login($products[1], $user->email, 'password', $hwid)['token'];
    $this->race(['device-reset', 'heartbeat'], ['binding_scope' => 'global', 'user_id' => $user->id, 'reset_product_id' => $products[0]->id, 'product_id' => $products[1]->id, 'token' => $token, 'free_until' => config('host_client.free_until')], $firstOperation);
    $this->assertDatabaseCount('client_sessions', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    expect(DeviceBinding::first()->hwid)->toBeNull();
})->with(['device-reset', 'heartbeat']);

test('MySQL cancellation and worker delivery serialize without partial credit', function (string $firstOperation) {
    $this->travelTo(now()->startOfSecond());
    $admin = User::factory()->create();
    Gate::define('licensekit.manage', fn ($actor): bool => $actor->id === $admin->id);
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    $operation = app(ManageOutages::class)->addTime($entitlement->product, 86400, now()->toImmutable(), 'credit', 'Goodwill', $admin);
    $results = $this->race(['apply', 'cancel'], ['operation_id' => $operation->id, 'product_id' => $entitlement->product_id, 'actor_id' => $admin->id], $firstOperation);
    if ($firstOperation === 'cancel') {
        expect($operation->fresh()->status)->toBe('canceled');
        expect($entitlement->fresh()->expires_at->equalTo($entitlement->expires_at))->toBeTrue();
    } else {
        expect($operation->fresh()->status)->toBe('completed');
        expect($results[1])->toBe('operation_conflict');
        expect($entitlement->fresh()->expires_at->equalTo($entitlement->expires_at->addDay()))->toBeTrue();
    }
})->with(['apply', 'cancel']);

test('MySQL finite renewal and manual credits support permitted dates beyond timestamp range', function () {
    $this->travelTo(now()->setDate(2026, 1, 1)->startOfDay());
    $user = User::factory()->create();
    $admin = User::factory()->create();
    Gate::define('licensekit.manage', fn ($actor): bool => $actor->id === $admin->id);
    $plan = LicensePlan::factory()->create(['duration_days' => 3650]);
    $keys = app(CreateBatch::class)->create($plan, 2, 'long-terms', 'fixture')->keys;
    app(ClaimLicense::class)->claim($user, $keys[0]->key);
    $entitlement = app(ClaimLicense::class)->claim($user, $keys[1]->key);
    expect($entitlement->expires_at->year)->toBeGreaterThan(2038);
    $old = $entitlement->expires_at;
    $operation = app(ManageOutages::class)->addTime($plan->product, 315360000, now()->toImmutable(), 'long-credit', 'Goodwill', $admin);
    (new ApplyCompensation($operation->id))->handle(app(Audit::class));
    expect($entitlement->fresh()->expires_at->equalTo($old->addSeconds(315360000)))->toBeTrue();
});
