<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Actions\SerialKey;
use LicenseKit\Core\Events\EntitlementGranted;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\ApiClient;
use LicenseKit\Core\Models\AuditEvent;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Tests\Core\Fixtures\User;

beforeEach(function () {
    $this->management = true;
    $this->refreshApplication();
    app('migrator')->path(__DIR__.'/../Fixtures/migrations');
    app('migrator')->path(__DIR__.'/../../../vendor/laravel/sanctum/database/migrations');
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
});

test('backend rejects invalid plan and key duration days', function (mixed $days) {
    expect(fn () => LicensePlan::factory()->create(['duration_days' => $days]))->toThrow(ValidationException::class);
    expect(fn () => LicenseKey::factory()->create(['duration_days' => $days]))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('licensekit_license_keys', 0);
})->with([0, -1, 0.5, 1.5, '', 3651]);

test('fresh schema stores finite and lifetime terms directly in days', function () {
    $plan = LicensePlan::factory()->create(['duration_days' => 2]);
    $key = LicenseKey::factory()->create(['license_plan_id' => $plan->id, 'duration_days' => 2]);
    $lifetime = LicensePlan::factory()->lifetime()->create();

    expect(DB::table('licensekit_license_plans')->where('id', $plan->id)->value('duration_days'))->toBe(2);
    expect(DB::table('licensekit_license_keys')->where('id', $key->id)->value('duration_days'))->toBe(2);
    expect($lifetime->fresh()->duration_days)->toBeNull();
    foreach (['licensekit_license_plans', 'licensekit_license_keys'] as $table) {
        expect(Schema::hasColumn($table, 'duration_seconds'))->toBeFalse();
    }
});

test('batch creation creates serial keys without stock and replays same batch', function () {
    $plan = LicensePlan::factory()->create();
    $issuer = app(CreateBatch::class);

    $batch = $issuer->create($plan, 2, 'purchase-1', 'admin:1');
    $plan->update(['duration_days' => 1]);
    $replay = $issuer->create($plan, 2, 'purchase-1', 'admin:1');

    expect($replay->id)->toBe($batch->id);
    expect($replay->keys->pluck('key')->all())->toBe($batch->keys->pluck('key')->all());
    foreach ($batch->keys as $key) {
        expect($key->key)->toMatch('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{5}(?:-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{5}){4}$/');
        $this->assertDatabaseHas('licensekit_license_keys', ['id' => $key->id, 'key' => $key->key, 'duration_days' => 30]);
        expect($key->toArray())->not->toHaveKey('key');
    }
    $this->assertDatabaseCount('licensekit_license_keys', 2);
    expect(AuditEvent::get()->toJson())->not->toContain($batch->keys->first()->key);
});

test('batch creation queries each locked plan once and hydrates only batch keys', function () {
    $plan = LicensePlan::factory()->create();
    DB::enableQueryLog();
    DB::flushQueryLog();

    $batch = app(CreateBatch::class)->create($plan, 251, 'bulk', 'test');
    $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with($query['query'], 'select'));
    DB::disableQueryLog();

    expect($batch->keys)->toHaveCount(251);
    expect($queries->filter(fn (array $query): bool => str_contains($query['query'], 'from "licensekit_license_plans"')))->toHaveCount(1);
    expect($queries->filter(fn (array $query): bool => str_contains($query['query'], 'from "licensekit_license_keys"')))->toHaveCount(1);
    expect($queries->filter(fn (array $query): bool => str_contains($query['query'], 'from "licensekit_products"')))->toHaveCount(2);
});

test('admin claims use locked actors and keys without serial lookup', function (bool $sameUser) {
    $actor = testAdminActor();
    $user = $sameUser ? $actor : User::factory()->create();
    $plan = LicensePlan::factory()->create();
    $key = app(CreateBatch::class)->create($plan, 1, 'admin-claim', 'test')->keys->first();
    DB::enableQueryLog();
    DB::flushQueryLog();

    $entitlement = app(ClaimLicense::class)->claimFor($actor, $user, $key);
    $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with($query['query'], 'select'));
    DB::disableQueryLog();

    expect($queries->filter(fn (array $query): bool => str_contains($query['query'], 'from "users"')))->toHaveCount(1);
    expect($queries->filter(fn (array $query): bool => str_contains($query['query'], 'from "licensekit_license_keys"')))->toHaveCount(1);
    $this->assertDatabaseHas('licensekit_license_keys', ['id' => $key->id, 'entitlement_id' => $entitlement->id]);
    $this->assertDatabaseHas('licensekit_audit_events', ['event' => 'key_claimed', 'actor' => 'user:'.$actor->id]);
})->with([true, false]);

test('claim listener or audit failure rolls back customer and administrative claims', function (bool $admin, string $failure) {
    $actor = testAdminActor();
    $user = User::factory()->create();
    $key = app(CreateBatch::class)->create(LicensePlan::factory()->create(), 1, 'rollback', 'test')->keys->first();
    if ($failure === 'listener') {
        Event::listen(EntitlementGranted::class, fn () => throw new RuntimeException('claim failed'));
    } else {
        AuditEvent::creating(fn () => throw new RuntimeException('claim failed'));
    }

    expect(fn () => $admin ? app(ClaimLicense::class)->claimFor($actor, $user, $key) : app(ClaimLicense::class)->claim($user, $key->key))
        ->toThrow(RuntimeException::class, 'claim failed');

    expect($key->fresh()->claimed_at)->toBeNull();
    $this->assertDatabaseCount('licensekit_entitlements', 0);
    $this->assertDatabaseMissing('licensekit_audit_events', ['event' => 'key_claimed']);
})->with([true, false])->with(['listener', 'audit']);

test('administrative claim rechecks target and key eligibility without partial writes', function (string $failure, string $error) {
    $actor = testAdminActor();
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    $key = app(CreateBatch::class)->create($plan, 1, 'eligibility', 'test')->keys->first();
    match ($failure) {
        'suspended' => $user->newQuery()->whereKey($user->id)->update(['is_suspended' => true]),
        'unverified' => $user->newQuery()->whereKey($user->id)->update(['email_verified_at' => null]),
        'deleted' => $user->delete(),
        'revoked' => $key->newQuery()->whereKey($key->id)->update(['revoked_at' => now()]),
    };

    expect(fn () => app(ClaimLicense::class)->claimFor($actor, $user, $key))->toThrow(LicensingException::class, $error);

    expect($key->fresh()->claimed_at)->toBeNull();
    $this->assertDatabaseCount('licensekit_entitlements', 0);
    $this->assertDatabaseMissing('licensekit_audit_events', ['event' => 'key_claimed']);
})->with([
    ['suspended', 'user unavailable'], ['unverified', 'user unavailable'], ['deleted', 'user unavailable'],
    ['revoked', 'invalid key'],
]);

test('claim normalizes ungrouped lowercase key and starts timer once', function () {
    $this->travelTo(now()->setDate(2026, 1, 1)->startOfDay());
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    $key = app(CreateBatch::class)->create($plan, 1, 'sale', 'admin:1')->keys->first();

    $result = app(ClaimLicense::class)->claim($user, ' '.strtolower(str_replace('-', '', $key->key)).' ');

    expect($result->expires_at->toIso8601String())->toBe('2026-01-31T00:00:00+00:00');
    $this->assertDatabaseHas('licensekit_license_keys', ['id' => $key->id, 'entitlement_id' => $result->id]);
    expect(fn () => app(ClaimLicense::class)->claim($user, $key->key))->toThrow(LicensingException::class, 'key already claimed');
    $this->assertDatabaseCount('licensekit_entitlements', 1);
});

test('renewal adds time while expired access restarts at claim time', function (bool $expired, string $expected) {
    $this->travelTo(now()->setDate(2026, 1, 1)->startOfDay());
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $plan->product_id, 'expires_at' => $expired ? now()->subDay() : now()->addDays(10)]);
    $key = app(CreateBatch::class)->create($plan, 1, 'sale', 'admin:1')->keys->first();

    $result = app(ClaimLicense::class)->claim($user, $key->key);

    expect($result->id)->toBe($entitlement->id);
    expect($result->expires_at->toDateString())->toBe($expected);
    $this->assertDatabaseCount('licensekit_entitlements', 1);
})->with(['active' => [false, '2026-02-10'], 'expired' => [true, '2026-01-31']]);

test('lifetime upgrade clears expiry and rejects another key without consuming it', function () {
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->lifetime()->create();
    $keys = app(CreateBatch::class)->create($plan, 2, 'sale', 'admin:1')->keys;

    $result = app(ClaimLicense::class)->claim($user, $keys[0]->key);
    expect($result->is_lifetime)->toBeTrue();
    expect($result->expires_at)->toBeNull();
    expect(fn () => app(ClaimLicense::class)->claim($user, $keys[1]->key))->toThrow(LicensingException::class, 'already lifetime');

    expect($keys[1]->fresh()->claimed_at)->toBeNull();
});

test('separate batches always generate distinct keys without allocating earlier keys', function () {
    $plan = LicensePlan::factory()->create();
    $creator = app(CreateBatch::class);
    $first = $creator->create($plan, 1, 'first', 'admin:1');
    $second = $creator->create($plan, 2, 'second', 'admin:1');
    expect($second->keys->pluck('id')->all())->not->toContain($first->keys->first()->id);
    expect($creator->create($plan, 2, 'second', 'admin:1')->id)->toBe($second->id);
    $this->assertDatabaseCount('licensekit_batches', 2);
    $this->assertDatabaseCount('licensekit_license_keys', 3);
});

test('new batches snapshot current terms without changing earlier revoked keys', function () {
    $plan = LicensePlan::factory()->create();
    $first = app(CreateBatch::class)->create($plan, 1, 'first', 'admin:1');
    $first->keys[0]->update(['revoked_at' => now()]);
    $plan->update(['duration_days' => 1]);
    $second = app(CreateBatch::class)->create($plan, 1, 'second', 'admin:1');
    expect($first->keys[0]->fresh()->duration_days)->toBe(30);
    expect($first->keys[0]->fresh()->revoked_at)->not->toBeNull();
    expect($second->keys[0]->duration_days)->toBe(1);
});

test('generated keys are immediately claimable without export or allocation', function () {
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    $batch = app(CreateBatch::class)->create($plan, 1, 'created', 'admin:1');
    $entitlement = app(ClaimLicense::class)->claim($user, $batch->keys[0]->key);
    expect($entitlement->user_id)->toBe($user->id);
    expect($batch->keys[0]->fresh()->claimed_at)->not->toBeNull();
});

test('revoked access cannot be restored by claiming another key', function () {
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $plan->product_id, 'is_revoked' => true]);
    $key = app(CreateBatch::class)->create($plan, 1, 'sale', 'admin:1')->keys->first();

    expect(fn () => app(ClaimLicense::class)->claim($user, $key->key))->toThrow(LicensingException::class, 'entitlement revoked');

    expect($key->fresh()->claimed_at)->toBeNull();
});

test('eligible verified user is required for PHP claims', function () {
    $user = User::factory()->unverified()->create();

    expect(fn () => app(ClaimLicense::class)->claim($user, 'invalid'))->toThrow(LicensingException::class, 'user unavailable');

    $this->assertDatabaseCount('licensekit_license_keys', 0);
});

test('unified issuance enforces permissions and idempotent references', function () {
    $plan = LicensePlan::factory()->create();
    $client = ApiClient::factory()->create([
        'all_products' => false, 'allowed_product_ids' => [$plan->product_id],
        'permissions' => ['licenses:create', 'keys:read'], 'maximum_quantity' => 2,
    ]);
    $token = $client->createToken('licensekit:api', ['licensekit:api', 'licenses:create', 'keys:read'], now()->addDay())->plainTextToken;
    $payload = ['plan_id' => $plan->id, 'quantity' => 1, 'reference' => 'order-1'];

    $first = $this->withToken($token)->postJson(route('licensekit.management.batches.store'), $payload)->assertCreated();
    $this->postJson(route('licensekit.management.batches.store'), $payload)->assertOk()->assertJsonPath('data.keys', $first->json('data.keys'));
    $this->postJson(route('licensekit.management.batches.store'), [...$payload, 'quantity' => 2])->assertConflict();
    $this->postJson(route('licensekit.management.batches.store'), [...$payload, 'quantity' => 3])->assertForbidden();
    $otherPlan = LicensePlan::factory()->create();
    $this->postJson(route('licensekit.management.batches.store'), [...$payload, 'plan_id' => $otherPlan->id])->assertNotFound();

    $this->assertDatabaseCount('licensekit_license_keys', 1);
});

test('serial generator retries collision without overwriting existing key', function () {
    $plan = LicensePlan::factory()->create();
    $existing = LicenseKey::factory()->create(['license_plan_id' => $plan->id]);
    $next = 'AAAAA-BBBBB-CCCCC-DDDDD-EEEEE';
    $this->mock(SerialKey::class)->shouldReceive('generate')->twice()->andReturn($existing->key, $next);

    $key = app(CreateBatch::class)->create($plan, 1, 'collision', 'admin:1')->keys->first();

    expect($key->key)->toBe($next);
    $this->assertDatabaseCount('licensekit_license_keys', 2);
});

test('issued key retains purchased duration after plan edit and cannot move products', function () {
    $this->travelTo(now()->setDate(2026, 1, 1)->startOfDay());
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    $key = app(CreateBatch::class)->create($plan, 1, 'sale', 'admin:1')->keys->first();
    $plan->update(['duration_days' => 1]);

    $entitlement = app(ClaimLicense::class)->claim($user, $key->key);

    expect($entitlement->expires_at->toDateString())->toBe('2026-01-31');
    $other = Product::factory()->create();
    expect(fn () => $plan->update(['product_id' => $other->id]))->toThrow(LicensingException::class, 'plan product is immutable');
});

test('expired and disabled integration credentials cannot issue keys', function () {
    $plan = LicensePlan::factory()->create();
    $client = ApiClient::factory()->state(['all_products' => true, 'permissions' => ['licenses:create', 'keys:read']])->create();
    $expired = $client->createToken('licensekit:api', ['licensekit:api', 'licenses:create', 'keys:read'], now()->subSecond())->plainTextToken;
    $active = $client->createToken('licensekit:api', ['licensekit:api', 'licenses:create', 'keys:read'], now()->addDay())->plainTextToken;
    $payload = ['plan_id' => $plan->id, 'quantity' => 1, 'reference' => 'sale'];

    $this->withToken($expired)->postJson(route('licensekit.management.batches.store'), $payload)->assertUnauthorized();
    $client->update(['is_enabled' => false]);
    $this->withToken($active)->postJson(route('licensekit.management.batches.store'), $payload)->assertUnauthorized();

    $this->assertDatabaseCount('licensekit_license_keys', 0);
});

test('generated batches preserve finite and lifetime snapshots and normalized labels', function (?int $days) {
    $plan = LicensePlan::factory()->create(['duration_days' => $days]);
    $batch = app(CreateBatch::class)->create($plan, 1, 'giveaway', 'admin:1', label: ' October giveaway ');
    $blank = app(CreateBatch::class)->create($plan, 1, 'sale', 'admin:1', label: '   ');
    expect($batch->duration_days)->toBe($days);
    expect($batch->keys[0]->duration_days)->toBe($days);
    expect($batch->keys[0]->batch_id)->toBe($batch->id);
    expect($batch->label)->toBe('October giveaway');
    expect($blank->label)->toBeNull();
    $this->assertDatabaseCount('licensekit_entitlements', 0);
})->with(['finite' => 30, 'lifetime' => null]);

test('issuance replay retains original metadata and conflicts on plan or quantity', function () {
    $plan = LicensePlan::factory()->create();
    $issuer = app(CreateBatch::class);
    $batch = $issuer->create($plan, 1, 'sale', 'admin:1', label: 'Original');
    $plan->update(['duration_days' => 1, 'is_enabled' => false]);

    $replay = $issuer->create($plan, 1, 'sale', 'admin:1', label: 'Replacement');
    expect($replay->id)->toBe($batch->id);
    expect($replay->label)->toBe('Original');
    expect($replay->duration_days)->toBe(30);
    expect($replay->keys->pluck('key')->all())->toBe($batch->keys->pluck('key')->all());
    expect(fn () => $issuer->create($plan, 2, 'sale', 'admin:1'))->toThrow(LicensingException::class, 'batch conflict');
    $other = LicensePlan::factory()->create();
    expect(fn () => $issuer->create($other, 1, 'sale', 'admin:1'))->toThrow(LicensingException::class, 'batch conflict');
    $this->assertDatabaseCount('licensekit_batches', 1);
    $this->assertDatabaseCount('licensekit_license_keys', 1);
});

test('revoked creation permission rejects fresh requests and replays', function () {
    $plan = LicensePlan::factory()->create();
    $client = ApiClient::factory()->create(['all_products' => true, 'permissions' => ['licenses:create', 'keys:read']]);
    $token = $client->createToken('licensekit:api', ['licensekit:api', ...$client->permissions], now()->addDay())->plainTextToken;
    $payload = ['plan_id' => $plan->id, 'quantity' => 1, 'reference' => 'sale'];
    $this->withToken($token)->postJson(route('licensekit.management.batches.store'), $payload)->assertCreated()->assertJsonMissingPath('data.method');
    $client->update(['permissions' => []]);
    $this->postJson(route('licensekit.management.batches.store'), $payload)->assertForbidden();
    $this->postJson(route('licensekit.management.batches.store'), [...$payload, 'reference' => 'new'])->assertForbidden();
    $this->assertDatabaseCount('licensekit_batches', 1);
    $this->assertDatabaseCount('licensekit_license_keys', 1);
});

test('removed stock and issuance endpoints never create keys', function () {
    $client = ApiClient::factory()->create(['all_products' => true, 'permissions' => ['licenses:create']]);
    $token = $client->createToken('licensekit:api', ['licensekit:api', ...$client->permissions], now()->addDay())->plainTextToken;
    $plan = LicensePlan::factory()->create();
    foreach (['stock-generations', 'issuance-batches'] as $path) {
        $this->withToken($token)->postJson('/api/licensekit/v1/manage/'.$path, ['plan_id' => $plan->id, 'quantity' => 1, 'reference' => 'old'])->assertNotFound();
    }
    $this->getJson('/api/licensekit/v1/manage/plans/'.$plan->id.'/stock')->assertNotFound();
    $this->assertDatabaseCount('licensekit_license_keys', 0);
});

test('API rejects method selection and oversized label without creating keys', function (array $extra, string $field) {
    $plan = LicensePlan::factory()->create();
    $client = ApiClient::factory()->state(['all_products' => true, 'permissions' => ['licenses:create', 'keys:read']])->create();
    $token = $client->createToken('licensekit:api', ['licensekit:api', 'licenses:create', 'keys:read'], now()->addDay())->plainTextToken;
    $this->withToken($token)->postJson(route('licensekit.management.batches.store'), [
        'plan_id' => $plan->id, 'quantity' => 1, 'reference' => 'sale', ...$extra,
    ])->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('licensekit_batches', 0);
    $this->assertDatabaseCount('licensekit_license_keys', 0);
})->with([
    'pool' => [['method' => 'pool'], 'method'],
    'on demand' => [['method' => 'on_demand'], 'method'],
    'null' => [['method' => null], 'method'],
    'unsupported' => [['method' => 'automatic'], 'method'],
    'oversized label' => [['label' => str_repeat('x', 256)], 'label'],
]);

test('backend rejects oversized labels before generating keys', function () {
    $plan = LicensePlan::factory()->create();
    expect(fn () => app(CreateBatch::class)->create($plan, 1, 'sale', 'admin:1', label: str_repeat('x', 256)))
        ->toThrow(LicensingException::class, 'invalid batch label');
    $this->assertDatabaseCount('licensekit_batches', 0);
    $this->assertDatabaseCount('licensekit_license_keys', 0);
});

test('fresh schema has one required batch association and no stock or method storage', function () {
    expect(Schema::hasTable('licensekit_stock_generations'))->toBeFalse();
    expect(Schema::hasTable('licensekit_issuance_batches'))->toBeFalse();
    expect(Schema::hasColumn('licensekit_api_clients', 'allowed_methods'))->toBeFalse();
    expect(Schema::hasColumn('licensekit_batches', 'method'))->toBeFalse();
    expect(Schema::hasColumn('licensekit_license_keys', 'stock_generation_id'))->toBeFalse();
    $key = LicenseKey::factory()->create();
    expect($key->batch_id)->not->toBeNull();
    expect($key->batch->license_plan_id)->toBe($key->license_plan_id);
});

test('failed generation rolls back batch and already generated keys', function () {
    $plan = LicensePlan::factory()->create();
    $this->mock(SerialKey::class)->shouldReceive('generate')->times(20)->andReturn('AAAAA-BBBBB-CCCCC-DDDDD-EEEEE');
    expect(fn () => app(CreateBatch::class)->create($plan, 2, 'sale', 'admin:1'))
        ->toThrow(LicensingException::class, 'key generation failed');
    $this->assertDatabaseCount('licensekit_batches', 0);
    $this->assertDatabaseCount('licensekit_license_keys', 0);
    expect(AuditEvent::where('event', 'batch_created')->count())->toBe(0);
});

test('key batch association cannot be cleared or deleted', function (string $mutation) {
    $key = LicenseKey::factory()->create();
    expect(fn () => match ($mutation) {
        'clear' => $key->update(['batch_id' => null]),
        'delete' => $key->batch->delete(),
    })->toThrow(QueryException::class);
    expect($key->fresh()->batch_id)->not->toBeNull();
    $this->assertDatabaseCount('licensekit_batches', 1);
})->with(['clear', 'delete']);
