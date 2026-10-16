<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LicenseKit\Core\Actions\Audit;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Actions\ManageApiClients;
use LicenseKit\Core\Actions\ManagementOperations;
use LicenseKit\Core\Actions\ManagementQueries;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\ApiClient;
use LicenseKit\Core\Models\AuditEvent;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Tests\Core\Fixtures\User;

function bootManagementApi(bool $enabled = true): void
{
    test()->management = $enabled;
    test()->refreshApplication();
    app('migrator')->path(__DIR__.'/../Fixtures/migrations');
    app('migrator')->path(__DIR__.'/../../../vendor/laravel/sanctum/database/migrations');
    test()->artisan('migrate', ['--force' => true])->assertSuccessful();
}

/** @param array<string, mixed> $attributes
 * @return array{ApiClient, string} */
function managementCredential(array $attributes = []): array
{
    $client = ApiClient::factory()->create(['all_products' => true, 'permissions' => array_keys(ApiClient::permissionOptions()),
        ...$attributes]);
    $token = $client->createToken('licensekit:api', ['licensekit:api', ...$client->permissions], now()->addHour())->plainTextToken;

    return [$client, $token];
}

beforeEach(function () {
    bootManagementApi();
});

test('management catalog reports product HWID requirement as boolean', function (bool $required) {
    [$client, $token] = managementCredential();
    Product::factory()->create(['requires_hwid' => $required]);

    $this->withToken($token)->getJson('/api/licensekit/v1/manage/products')->assertOk()->assertJsonPath('data.0.requires_hwid', $required);
})->with([true, false]);

test('management HTTP is opt in while core operations and fulfillment remain available', function (bool $enabled) {
    bootManagementApi($enabled);
    expect(Route::has('licensekit.management.products.index'))->toBeFalse();
    expect(Route::has('licensekit.licenses.issue'))->toBeFalse();
    [$client, $token] = managementCredential();
    $plan = LicensePlan::factory()->create();
    $batch = app(ManagementOperations::class)->create($token, $plan->id, 1, 'backend');
    expect($batch->issuer)->toBe('api:'.$client->id);
    $this->getJson('/api/licensekit/v1/manage/products')->assertNotFound();
})->with([false]);

test('management authenticates before lookup and rejects absent malformed and wrong purpose tokens with 401', function () {
    $this->getJson('/api/licensekit/v1/manage/batches/999')->assertUnauthorized()->assertJsonPath('error', 'invalid_token')->assertHeader('Cache-Control', 'no-store, private');
    $integration = ApiClient::factory()->state(['all_products' => true, 'permissions' => ['licenses:create', 'keys:read']])->create();
    $token = $integration->createToken('old-purpose', ['licensekit:api', 'batches:read'], now()->addHour())->plainTextToken;
    $this->withToken($token)->getJson('/api/licensekit/v1/manage/batches/999')->assertUnauthorized();
    [$client] = managementCredential();
    foreach (['old-issuance', 'desktop:1'] as $purpose) {
        $wrong = $client->createToken($purpose, ['licensekit:api', 'batches:read'], now()->addHour())->plainTextToken;
        $this->withToken($wrong)->getJson('/api/licensekit/v1/manage/batches/999')->assertUnauthorized();
    }
    $this->withToken('invalid')->postJson('/api/licensekit/v1/manage/batches', [])->assertUnauthorized();
});

test('management token cannot authenticate customer fulfillment or desktop requests', function () {
    [$client, $token] = managementCredential();
    $plan = LicensePlan::factory()->create();
    $this->withToken($token)->getJson('/api/licensekit/v1/licenses')->assertNotFound();
    $this->postJson('/api/licensekit/v1/licenses/issue', ['plan_id' => $plan->id, 'quantity' => 1, 'reference' => 'wrong'])->assertNotFound();
    $this->postJson('/api/licensekit/v1/products/'.$plan->product->slug.'/heartbeat', ['hwid' => 'pc'])->assertUnauthorized();
    $this->assertDatabaseCount('licensekit_license_keys', 0);
});

test('expired disabled and revoked management credentials give 401 at exact deadline', function (string $state) {
    $this->freezeTime();
    [$client, $token] = managementCredential();
    if ($state === 'expired') {
        $this->travelTo(now()->addHour());
    } elseif ($state === 'disabled') {
        $client->update(['is_enabled' => false]);
    } else {
        $client->tokens()->delete();
    }
    $this->withToken($token)->getJson('/api/licensekit/v1/manage/products')->assertUnauthorized();
})->with(['expired', 'disabled', 'revoked']);

test('product selection scopes catalog and writes with 404 for inaccessible records', function () {
    $allowed = LicensePlan::factory()->create();
    $denied = LicensePlan::factory()->create();
    [$client, $token] = managementCredential(['all_products' => false, 'allowed_product_ids' => [$allowed->product_id]]);
    $this->withToken($token)->getJson('/api/licensekit/v1/manage/products')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $allowed->product_id);
    $this->getJson('/api/licensekit/v1/manage/plans')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $allowed->id);
    $this->getJson('/api/licensekit/v1/manage/plans/'.$denied->id.'/stock')->assertNotFound();
    $this->postJson('/api/licensekit/v1/manage/batches', ['plan_id' => $denied->id, 'quantity' => 1, 'reference' => 'denied'])->assertNotFound();
    $client->update(['allowed_product_ids' => []]);
    $this->getJson('/api/licensekit/v1/manage/products')->assertOk()->assertJsonCount(0, 'data');
    $this->assertDatabaseCount('licensekit_license_keys', 0);
});

test('explicit all products grant includes later products and bounded catalog filters retain isolation', function () {
    [$client, $token] = managementCredential();
    $first = LicensePlan::factory()->create(['product_id' => Product::factory()->create(['name' => 'Find this', 'slug' => 'find-this'])->id]);
    $second = LicensePlan::factory()->create();
    $this->withToken($token)->getJson('/api/licensekit/v1/manage/products?search=find-this&per_page=1')->assertOk()->assertJsonPath('data.0.id', $first->product_id)->assertJsonPath('meta.total', 1);
    $this->getJson('/api/licensekit/v1/manage/plans?product_id='.$second->product_id)->assertOk()->assertJsonPath('data.0.id', $second->id)->assertJsonCount(1, 'data');
    $this->getJson('/api/licensekit/v1/manage/products?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    $this->getJson('/api/licensekit/v1/manage/products?search=%27%20OR%201=1')->assertOk()->assertJsonCount(0, 'data');
});

test('current permissions intersect token snapshot and added permissions require rotation', function () {
    [$client, $token] = managementCredential(['permissions' => ['keys:read']]);
    $plan = LicensePlan::factory()->create();
    $own = app(CreateBatch::class)->create($plan, 1, 'own', 'api:'.$client->id);
    $client->update(['permissions' => ['keys:read', 'batches:read-all']]);
    $other = app(CreateBatch::class)->create($plan, 1, 'other', 'integration:9');
    $this->withToken($token)->getJson('/api/licensekit/v1/manage/batches/'.$other->id)->assertNotFound();
    $this->getJson('/api/licensekit/v1/manage/batches/'.$own->id.'/keys')->assertOk();
    $client->update(['permissions' => []]);
    $this->getJson('/api/licensekit/v1/manage/batches/'.$own->id.'/keys')->assertForbidden();
    $this->getJson('/api/licensekit/v1/manage/products')->assertOk();
});

test('catalog and own batches are available without extra permissions', function () {
    [$client, $token] = managementCredential(['permissions' => []]);
    $plan = LicensePlan::factory()->create();
    $own = app(CreateBatch::class)->create($plan, 1, 'own', 'api:'.$client->id);
    $other = app(CreateBatch::class)->create($plan, 1, 'other', 'integration:9');
    $this->withToken($token)->getJson('/api/licensekit/v1/manage/products')->assertOk()->assertJsonPath('data.0.id', $plan->product_id);
    $this->getJson('/api/licensekit/v1/manage/plans')->assertOk()->assertJsonPath('data.0.id', $plan->id);
    $this->getJson('/api/licensekit/v1/manage/batches')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
    $this->getJson('/api/licensekit/v1/manage/batches/'.$other->id)->assertNotFound();
    $this->getJson('/api/licensekit/v1/manage/batches/'.$own->id.'/keys')->assertForbidden();
    $this->postJson('/api/licensekit/v1/manage/batches', ['plan_id' => $plan->id, 'quantity' => 1, 'reference' => 'denied'])->assertForbidden();
});

test('creation enforces quantity and current product grants even on replay', function (string $restriction) {
    [$client, $token] = managementCredential();
    $plan = LicensePlan::factory()->create();
    $body = ['plan_id' => $plan->id, 'quantity' => 2, 'reference' => 'replay'];
    $this->withToken($token)->postJson('/api/licensekit/v1/manage/batches', $body)->assertCreated();
    $client->update(match ($restriction) {
        'quantity' => ['maximum_quantity' => 1],
        'product' => ['all_products' => false, 'allowed_product_ids' => []],
        'permission' => ['permissions' => []],
    });
    $this->postJson('/api/licensekit/v1/manage/batches', $body)->assertStatus($restriction === 'product' ? 404 : 403);
    $this->assertDatabaseCount('licensekit_license_keys', 2);
})->with(['quantity', 'product', 'permission']);

test('issue fifty keys workflow retries same batch after plan edits and retrieves sensitive keys explicitly', function () {
    [$client, $token] = managementCredential();
    $plan = LicensePlan::factory()->create();
    $body = ['plan_id' => $plan->id, 'quantity' => 50, 'reference' => 'giveaway-october', 'label' => ' October giveaway '];
    $created = $this->withToken($token)->postJson('/api/licensekit/v1/manage/batches', $body)->assertCreated()->assertJsonCount(50, 'data.keys')->assertJsonPath('data.label', 'October giveaway')->assertHeader('Cache-Control', 'no-store, private');
    $plan->update(['duration_days' => 1]);
    $this->postJson('/api/licensekit/v1/manage/batches', [...$body, 'label' => 'Changed'])->assertOk()->assertJsonPath('data.id', $created->json('data.id'))->assertJsonPath('data.duration_days', 30)->assertJsonPath('data.label', 'October giveaway');
    $id = $created->json('data.id');
    $this->getJson('/api/licensekit/v1/manage/batches/'.$id)->assertOk()->assertJsonMissingPath('data.keys');
    $this->getJson('/api/licensekit/v1/manage/batches/'.$id.'/keys')->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.total', 50);
    $this->getJson('/api/licensekit/v1/manage/batches/'.$id.'/keys?per_page=100')->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('data.0.key', $created->json('data.keys.0.key'));
    $this->getJson('/api/licensekit/v1/manage/batches?reference=giveaway-october')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.keys');
    $this->assertDatabaseCount('licensekit_license_keys', 50);
    $this->assertDatabaseCount('licensekit_licenses', 0);
    expect(AuditEvent::get()->toJson())->not->toContain($created->json('data.keys.0.key'));
});

test('issuance without key permission returns metadata and denies explicit key retrieval', function () {
    [$client, $token] = managementCredential(['permissions' => ['licenses:create']]);
    $plan = LicensePlan::factory()->create();
    $created = $this->withToken($token)->postJson('/api/licensekit/v1/manage/batches', ['plan_id' => $plan->id, 'quantity' => 1, 'reference' => 'metadata'])->assertCreated()->assertJsonMissingPath('data.keys');
    $this->getJson('/api/licensekit/v1/manage/batches/'.$created->json('data.id').'/keys')->assertForbidden();
});

test('batch visibility defaults to own issuer and all issuer permission never crosses product boundaries', function () {
    $allowed = LicensePlan::factory()->create();
    $denied = LicensePlan::factory()->create();
    [$client, $token] = managementCredential(['all_products' => false, 'allowed_product_ids' => [$allowed->product_id],
        'permissions' => ['keys:read']]);
    $own = app(CreateBatch::class)->create($allowed, 1, 'own', 'api:'.$client->id);
    $other = app(CreateBatch::class)->create($allowed, 1, 'other', 'integration:77');
    $outside = app(CreateBatch::class)->create($denied, 1, 'outside', 'api:'.$client->id);
    $this->withToken($token)->getJson('/api/licensekit/v1/manage/batches')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
    $this->getJson('/api/licensekit/v1/manage/batches/'.$other->id.'/keys')->assertNotFound();
    $client->update(['permissions' => ['keys:read', 'batches:read-all']]);
    $token = $client->createToken('licensekit:api', ['licensekit:api', ...$client->permissions], now()->addHour())->plainTextToken;
    $this->withToken($token)->getJson('/api/licensekit/v1/manage/batches')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonMissingPath('data.0.keys')->assertJsonMissingPath('data.1.keys');
    $this->getJson('/api/licensekit/v1/manage/batches/'.$other->id.'/keys')->assertOk();
    $this->getJson('/api/licensekit/v1/manage/batches/'.$outside->id)->assertNotFound();
});

test('created keys redeem immediately and no license exists before claim', function () {
    [$client, $token] = managementCredential();
    $plan = LicensePlan::factory()->create();
    $created = $this->withToken($token)->postJson('/api/licensekit/v1/manage/batches', ['plan_id' => $plan->id, 'quantity' => 2, 'reference' => 'purchase'])->assertCreated();
    $this->assertDatabaseCount('licensekit_licenses', 0);
    $user = User::factory()->create();
    $license = app(ClaimLicense::class)->claim($user, $created->json('data.keys.0.key'));
    expect($license->user_id)->toBe($user->id);
    $this->assertDatabaseCount('licensekit_license_keys', 2);
});

test('changed batch request returns 409 and issuer references remain independent', function (string $field) {
    [$client, $token] = managementCredential();
    $plan = LicensePlan::factory()->create();
    $other = LicensePlan::factory()->create();
    $body = ['plan_id' => $plan->id, 'quantity' => 1, 'reference' => 'same'];
    $this->withToken($token)->postJson('/api/licensekit/v1/manage/batches', $body)->assertCreated();
    $this->postJson('/api/licensekit/v1/manage/batches', [...$body, $field => $field === 'quantity' ? 2 : $other->id])->assertConflict()->assertJsonPath('error', 'batch_conflict');
    [$second, $secondToken] = managementCredential();
    $this->withToken($secondToken)->postJson('/api/licensekit/v1/manage/batches', $body)->assertCreated();
    $this->assertDatabaseCount('licensekit_batches', 2);
    $this->assertDatabaseCount('licensekit_license_keys', 2);
})->with(['quantity', 'plan_id']);

test('old method selection is rejected instead of silently generating keys', function (mixed $method) {
    [$client, $token] = managementCredential();
    $plan = LicensePlan::factory()->create();
    $this->withToken($token)->postJson('/api/licensekit/v1/manage/batches', ['plan_id' => $plan->id, 'quantity' => 2, 'reference' => 'old', 'method' => $method])->assertUnprocessable()->assertJsonValidationErrors('method');
    $this->assertDatabaseCount('licensekit_license_keys', 0);
    $this->assertDatabaseCount('licensekit_batches', 0);
})->with(['pool', 'on_demand', null, '']);

test('invalid management mutation input returns 422 without generating keys', function (array $change, string $field) {
    [$client, $token] = managementCredential();
    $plan = LicensePlan::factory()->create();
    $this->withToken($token)->postJson('/api/licensekit/v1/manage/batches', ['plan_id' => $plan->id, 'quantity' => 1, 'reference' => 'valid', ...$change])->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('licensekit_license_keys', 0);
})->with([
    [['quantity' => 0], 'quantity'], [['quantity' => 1001], 'quantity'], [['quantity' => 1.5], 'quantity'],
    [['reference' => ''], 'reference'], [['reference' => str_repeat('x', 129)], 'reference'],
    [['method' => 'unknown'], 'method'], [['method' => null], 'method'], [['label' => str_repeat('x', 256)], 'label'],
]);

test('failed batch audit rolls back keys and batch history', function () {
    [$client, $token] = managementCredential();
    $plan = LicensePlan::factory()->create();
    $this->mock(Audit::class)->shouldReceive('record')->withArgs(fn ($actor, $event) => $event === 'batch_created')->once()->andThrow(new RuntimeException('audit failed'));
    expect(fn () => app(ManagementOperations::class)->create($token, $plan->id, 2, 'rollback'))->toThrow(RuntimeException::class, 'audit failed');
    $this->assertDatabaseCount('licensekit_license_keys', 0);
    $this->assertDatabaseCount('licensekit_batches', 0);
});

test('readiness failure rejects management writes without side effects', function () {
    [$client, $token] = managementCredential();
    $plan = LicensePlan::factory()->create();
    config(['licensekit.user.guard' => 'missing']);
    $this->withToken($token)->postJson('/api/licensekit/v1/manage/batches', ['plan_id' => $plan->id, 'quantity' => 1, 'reference' => 'unavailable'])->assertStatus(503);
    $this->assertDatabaseCount('licensekit_license_keys', 0);
});

test('management limits reads per client independently of shared IP and configurable mutation limits', function () {
    config(['licensekit.management.rate_limits.reads' => 60, 'licensekit.management.rate_limits.writes' => 10]);
    [$client, $token] = managementCredential();
    $this->withToken($token);
    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/api/licensekit/v1/manage/products')->assertOk();
    }
    $this->getJson('/api/licensekit/v1/manage/products')->assertTooManyRequests();
    [$second, $secondToken] = managementCredential();
    $plan = LicensePlan::factory()->create();
    $this->withToken($secondToken)->getJson('/api/licensekit/v1/manage/products')->assertOk();
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/licensekit/v1/manage/batches', ['plan_id' => $plan->id, 'quantity' => 1, 'reference' => 'stock-'.$i])->assertCreated();
    }
    $this->postJson('/api/licensekit/v1/manage/batches', ['plan_id' => $plan->id, 'quantity' => 1, 'reference' => 'over-limit'])->assertTooManyRequests();
    $this->assertDatabaseCount('licensekit_license_keys', 10);
});

test('credential rotation narrows snapshots revokes previous token and audits no secrets', function () {
    $actor = User::factory()->create();
    Gate::define('licensekit.admin', fn (User $user) => $user->id === $actor->id);
    [$client, $old] = managementCredential();
    $new = app(ManageApiClients::class)->rotate($client, now()->toImmutable()->addDay(), $actor);
    $this->withToken($old)->getJson('/api/licensekit/v1/manage/products')->assertUnauthorized();
    $this->withToken($new)->getJson('/api/licensekit/v1/manage/products')->assertOk();
    expect(AuditEvent::get()->toJson())->not->toContain($old)->not->toContain($new);
    app(ManageApiClients::class)->revoke($client, $actor);
    $this->getJson('/api/licensekit/v1/manage/products')->assertUnauthorized();
});

test('invalid token expiry and failed rotation audit preserve previous credentials', function (bool $auditFails) {
    $actor = User::factory()->create();
    Gate::define('licensekit.admin', fn () => true);
    [$client, $old] = managementCredential();
    if ($auditFails) {
        $this->mock(Audit::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('audit failed'));
    }
    expect(fn () => app(ManageApiClients::class)->rotate($client, now()->toImmutable()->addSeconds($auditFails ? 60 : -1), $actor))
        ->toThrow($auditFails ? RuntimeException::class : LicensingException::class);
    $this->withToken($old)->getJson('/api/licensekit/v1/manage/products')->assertOk();
    expect($client->tokens()->count())->toBe(1);
})->with([false, true]);

test('setup reports missing management storage without touching users', function (string $table) {
    Schema::drop($table);
    $this->artisan('licensekit:check')->expectsOutputToContain('Missing table: '.$table)->assertFailed();
})->with(['licensekit_api_clients', 'licensekit_batches']);

test('cacheable configuration and compiled routes preserve management registration and token authentication', function () {
    $configuration = eval('return '.var_export(config('licensekit'), true).';');
    config(['licensekit' => $configuration]);
    $router = app('router');
    $routes = $router->getRoutes();
    foreach ($routes as $route) {
        $route->prepareForSerialization();
    }
    $router->setCompiledRoutes($routes->compile());
    [$client, $token] = managementCredential();
    $this->withToken($token)->getJson('/api/licensekit/v1/manage/products')->assertOk();
    expect(Route::has('licensekit.licenses.issue'))->toBeFalse();
});

test('public management reads work without HTTP and enforce current product and serial permissions', function () {
    bootManagementApi(false);
    $plan = LicensePlan::factory()->create();
    LicensePlan::factory()->create(['product_id' => $plan->product_id]);
    $foreign = LicensePlan::factory()->create();
    [$client, $token] = managementCredential(['all_products' => false, 'allowed_product_ids' => [$plan->product_id], 'permissions' => []]);
    $batch = app(CreateBatch::class)->create($plan, 1, 'host-api', 'api:'.$client->id);
    $queries = app(ManagementQueries::class);
    expect($queries->products($token)->total())->toBe(1);
    expect($queries->plans($token)->total())->toBe(2);
    expect($queries->batch($token, $batch->id)->id)->toBe($batch->id);
    expect(fn () => $queries->keys($token, $batch->id))->toThrow(LicensingException::class, 'management forbidden');
    $outside = app(CreateBatch::class)->create($foreign, 1, 'outside', 'api:'.$client->id);
    expect(fn () => $queries->batch($token, $outside->id))->toThrow(ModelNotFoundException::class);
    expect(Route::has('licensekit.management.products.index'))->toBeFalse();
});

test('unauthenticated requests do not consume management client limits', function () {
    config(['licensekit.management.rate_limits.reads' => 1]);
    $this->getJson('/api/licensekit/v1/manage/products')->assertUnauthorized();
    $this->getJson('/api/licensekit/v1/manage/products')->assertUnauthorized();
    [$client, $token] = managementCredential();
    $this->withToken($token)->getJson('/api/licensekit/v1/manage/products')->assertOk();
    $this->getJson('/api/licensekit/v1/manage/products')->assertTooManyRequests();
});

test('missing management storage returns sanitized no-store failure and log', function () {
    [$client, $token] = managementCredential();
    Schema::drop('licensekit_products');
    Log::shouldReceive('error')->once()->with('LicenseKit storage unavailable', Mockery::on(fn (array $context): bool => array_keys($context) === ['exception', 'code']));
    $this->withToken($token)->getJson('/api/licensekit/v1/manage/products')->assertStatus(503)
        ->assertExactJson(['error' => 'licensing_unavailable', 'message' => 'licensing unavailable'])
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('read only HTTP clients see their own batches within allowed products', function () {
    Gate::define('licensekit.admin', fn () => true);
    $actor = User::factory()->create();
    $allowed = LicensePlan::factory()->create();
    $excluded = LicensePlan::factory()->create();
    [$client, $token] = managementCredential(['permissions' => ['keys:read'],
        'all_products' => false, 'allowed_product_ids' => [$allowed->product_id]]);
    $staffBatch = app(CreateBatch::class)->create($allowed, 2, 'own', 'api:'.$client->id, apiClientId: $client->id);
    $outside = app(CreateBatch::class)->createFor($actor, $excluded, 1, 'outside');
    $unrelated = app(CreateBatch::class)->createFor($actor, $allowed, 1, 'unrelated');
    $ownOutside = app(CreateBatch::class)->create($excluded, 1, 'own-outside', 'api:'.$client->id);
    $staffBatch->keys[0]->update(['revoked_at' => now()]);
    $this->withToken($token)->getJson('/api/licensekit/v1/manage/batches')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $staffBatch->id)
        ->assertJsonPath('data.0.total_count', 2)->assertJsonPath('data.0.unclaimed_count', 1)
        ->assertJsonPath('data.0.revoked_count', 1)->assertJsonMissingPath('data.0.keys');
    $this->getJson('/api/licensekit/v1/manage/batches/'.$staffBatch->id)->assertOk()->assertJsonPath('data.claimed_count', 0);
    $this->getJson('/api/licensekit/v1/manage/batches/'.$staffBatch->id.'/keys?status=unclaimed&per_page=1')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $staffBatch->keys[1]->id)->assertJsonPath('data.0.claimed_at', null)
        ->assertHeader('Cache-Control', 'no-store, private')->assertJsonMissingPath('data.0.license_id');
    foreach ([$outside, $unrelated, $ownOutside] as $hidden) {
        $this->getJson('/api/licensekit/v1/manage/batches/'.$hidden->id)->assertNotFound();
        $this->getJson('/api/licensekit/v1/manage/batches/'.$hidden->id.'/keys')->assertNotFound();
    }
    $this->postJson('/api/licensekit/v1/manage/batches', ['plan_id' => $allowed->id, 'quantity' => 1, 'reference' => 'denied'])->assertForbidden();
    $client->update(['permissions' => []]);
    $this->getJson('/api/licensekit/v1/manage/batches/'.$staffBatch->id.'/keys')->assertForbidden();
});

test('management key HTTP reads validate pagination and status', function (string $query) {
    [$client, $token] = managementCredential();
    $batch = app(ManagementOperations::class)->create($token, LicensePlan::factory()->create()->id, 1, 'validation');
    $this->withToken($token)->getJson('/api/licensekit/v1/manage/batches/'.$batch->id.'/keys?'.$query)->assertUnprocessable();
})->with(['per_page=101', 'per_page=0', 'page=0', 'status=sold']);
