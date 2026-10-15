<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LicenseKit\Core\Actions\Audit;
use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Actions\IssueLicenses;
use LicenseKit\Core\Actions\ManageApiClients;
use LicenseKit\Core\Actions\ManagementAccess;
use LicenseKit\Core\Actions\ManagementOperations;
use LicenseKit\Core\Actions\ManagementQueries;
use LicenseKit\Core\Actions\ResellerInventory;
use LicenseKit\Core\Data\ManagementAuthorization;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\ApiClient;
use LicenseKit\Core\Models\AuditEvent;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Tests\Core\Fixtures\CustomUser;
use LicenseKit\Tests\Core\Fixtures\SoftDeletedUser;
use LicenseKit\Tests\Core\Fixtures\UlidUser;
use LicenseKit\Tests\Core\Fixtures\User;
use LicenseKit\Tests\Core\Fixtures\UuidUser;

/** @return array{ApiClient, string} */
function resellerCredential(?User $owner = null): array
{
    $client = ApiClient::factory()->create(['owner_user_id' => $owner?->getKey(), 'all_products' => true,
        'permissions' => ['licenses:create', 'keys:read']]);

    return [$client, $client->createToken('licensekit:api', ['licensekit:api', ...$client->permissions], now()->addHour())->plainTextToken];
}

beforeEach(function () {
    Gate::define('licensekit.manage', fn () => true);
    config(['licensekit.local_integrations.store' => ['enabled' => true, 'all_products' => true,
        'maximum_quantity' => 10, 'disclose_serials' => true]]);
});

test('API ownership shares reseller batches while preserving client retry identities', function () {
    $owner = User::factory()->create();
    [$first, $token] = resellerCredential($owner);
    [$second, $otherToken] = resellerCredential($owner);
    $plan = LicensePlan::factory()->create();
    $batch = app(ManagementOperations::class)->create($token, $plan->id, 1, 'same');
    $other = app(ManagementOperations::class)->create($otherToken, $plan->id, 1, 'same');
    expect($batch->apiClient->is($first))->toBeTrue()->and($batch->reseller->is($owner))->toBeTrue()
        ->and($batch->created_by_user_id)->toBeNull()->and($other->id)->not->toBe($batch->id);
    expect(app(ManagementQueries::class)->batches($token)->pluck('id')->all())->toBe([$batch->id, $other->id]);
    expect(app(ManagementQueries::class)->keys($token, $other->id)->total())->toBe(1);
    $first->update(['name' => 'Renamed']);
    $token = app(ManageApiClients::class)->rotate($first, now()->toImmutable()->addDay(), $owner);
    expect(app(ManagementOperations::class)->create($token, $plan->id, 1, 'same')->id)->toBe($batch->id);
    expect($batch->issuer)->toBe('api:'.$first->id);
});

test('owner eligibility is checked for authentication and every management operation', function (string $state) {
    $owner = User::factory()->create();
    [$client, $token] = resellerCredential($owner);
    $plan = LicensePlan::factory()->create();
    $batch = app(ManagementOperations::class)->create($token, $plan->id, 1, 'sale');
    $owner->update($state === 'suspended' ? ['is_suspended' => true] : ['email_verified_at' => null]);
    foreach ([
        fn () => app(ManagementAccess::class)->authenticate($token),
        fn () => app(ManagementQueries::class)->products($token),
        fn () => app(ManagementQueries::class)->plans($token),
        fn () => app(ManagementQueries::class)->batch($token, $batch->id),
        fn () => app(ManagementQueries::class)->keys($token, $batch->id),
        fn () => app(ManagementOperations::class)->create($token, $plan->id, 1, 'sale'),
        fn () => app(ManagementOperations::class)->create($token, $plan->id, 1, 'new'),
    ] as $operation) {
        expect($operation)->toThrow(LicensingException::class, 'invalid token');
    }
    $owner->update(['is_suspended' => false, 'email_verified_at' => now()]);
    expect(app(ManagementQueries::class)->batch($token, $batch->id)->id)->toBe($batch->id);
    expect($batch->keys->first()->revoked_at)->toBeNull();
})->with(['suspended', 'unverified']);

test('ownerless integrations remain usable and host verification configuration applies to owners', function () {
    [, $unowned] = resellerCredential();
    $owner = User::factory()->create(['email_verified_at' => null]);
    [, $owned] = resellerCredential($owner);
    config(['licensekit.user.require_verified' => false]);
    expect(app(ManagementQueries::class)->products($unowned)->total())->toBe(0)
        ->and(app(ManagementQueries::class)->products($owned)->total())->toBe(0);
});

test('locked management operations recheck owner after initial authentication', function () {
    $owner = User::factory()->create();
    [, $token] = resellerCredential($owner);
    $access = new class extends ManagementAccess
    {
        public function authenticate(#[SensitiveParameter] string $bearer): ManagementAuthorization
        {
            $context = parent::authenticate($bearer);
            $context->client->owner->update(['is_suspended' => true]);

            return $context;
        }
    };
    expect(fn () => $access->run($token, null, fn () => 'must not run'))->toThrow(LicensingException::class, 'invalid token');
});

test('soft deleted and missing owners fail closed while restoration restores eligibility', function () {
    Schema::table('users', fn ($table) => $table->softDeletes());
    config(['auth.providers.users.model' => SoftDeletedUser::class]);
    $owner = SoftDeletedUser::create(User::factory()->raw());
    [$client, $token] = resellerCredential($owner);
    $owner->delete();
    expect(fn () => app(ResellerInventory::class)->batches($owner))->toThrow(LicensingException::class, 'user unavailable');
    expect(fn () => app(ManagementAccess::class)->authenticate($token))->toThrow(LicensingException::class, 'invalid token');
    $owner->restore();
    expect(app(ResellerInventory::class)->batches($owner)->total())->toBe(0);
    expect(app(ManagementAccess::class)->authenticate($token)->client->id)->toBe($client->id);
    DB::statement('PRAGMA defer_foreign_keys = ON');
    // Simulate an orphan from host maintenance with constraints disabled.
    DB::table('licensekit_api_clients')->where('id', $client->id)->update(['owner_user_id' => 999999]);
    expect(fn () => app(ManagementAccess::class)->authenticate($token))->toThrow(LicensingException::class, 'invalid token');
    DB::table('licensekit_api_clients')->where('id', $client->id)->update(['owner_user_id' => $owner->id]);
    DB::statement('PRAGMA defer_foreign_keys = OFF');
});

test('manual and local issuance distinguish creator from reseller and retries freeze attribution', function () {
    $actor = User::factory()->create();
    $reseller = User::factory()->create();
    $other = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    $manual = app(CreateBatch::class)->createFor($actor, $plan, 1, 'manual', reseller: $reseller);
    $local = app(IssueLicenses::class)->issue('store', $plan->id, 1, 'local', reseller: $reseller);
    $batch = Batch::findOrFail($local->batchId);
    expect($manual->creator->is($actor))->toBeTrue()->and($manual->reseller->is($reseller))->toBeTrue()
        ->and($manual->api_client_id)->toBeNull()->and($batch->reseller_user_id)->toBe($reseller->id)
        ->and($batch->created_by_user_id)->toBeNull()->and($batch->issuer)->toBe('local:store');
    expect(app(ResellerInventory::class)->batches($reseller)->pluck('id')->all())->toBe([$manual->id, $batch->id]);
    $reseller->update(['is_suspended' => true]);
    expect(app(CreateBatch::class)->createFor($actor, $plan, 1, 'manual', reseller: $other)->reseller_user_id)->toBe($reseller->id);
    expect(app(IssueLicenses::class)->issue('store', $plan->id, 1, 'local', reseller: $reseller)->replayed)->toBeTrue();
    expect(fn () => app(IssueLicenses::class)->issue('store', $plan->id, 1, 'new', reseller: $reseller))->toThrow(LicensingException::class, 'invalid reseller');
    expect(fn () => app(CreateBatch::class)->createFor($actor, $plan, 1, 'new', reseller: $reseller))->toThrow(LicensingException::class, 'invalid reseller');
    $reseller->delete();
    expect($manual->fresh()->reseller_user_id)->toBeNull();
    expect(app(IssueLicenses::class)->issue('store', $plan->id, 1, 'local', reseller: $reseller)->batchId)->toBe($batch->id);
    expect(AuditEvent::where('event', 'batch_created')->where('subject_id', $manual->id)->firstOrFail()->after['reseller_user_id'])->toBe($reseller->id);
});

test('retirement is required before owner deletion and permanently prevents revival', function () {
    $actor = User::factory()->create();
    $owner = User::factory()->create();
    [$client, $token] = resellerCredential($owner);
    $batch = app(ManagementOperations::class)->create($token, LicensePlan::factory()->create()->id, 1, 'sale');
    $client->update(['is_enabled' => false]);
    $staleClient = $client->fresh();
    expect(fn () => DB::transaction(fn () => DB::table('users')->where('id', $owner->id)->delete()))->toThrow(QueryException::class);
    app(ManageApiClients::class)->retire($client, $actor, '  Relationship ended  ');
    app(ManageApiClients::class)->retire($client, $actor, 'Already retired');
    $client->refresh();
    expect($client->retired_at)->not->toBeNull()->and($client->owner_user_id)->toBeNull()->and($client->is_enabled)->toBeFalse()
        ->and($client->tokens()->count())->toBe(0);
    expect(fn () => app(ManageApiClients::class)->rotate($client, now()->toImmutable()->addDay(), $actor, true))->toThrow(LicensingException::class);
    expect(fn () => $staleClient->createToken('licensekit:api'))->toThrow(LicensingException::class, 'management client retired');
    expect(fn () => $client->update(['is_enabled' => true]))->toThrow(LicensingException::class, 'management client retired');
    $client->refresh();
    expect(fn () => $client->update(['owner_user_id' => $actor->id]))->toThrow(LicensingException::class);
    $client->refresh()->retired_at = null;
    expect(fn () => $client->save())->toThrow(LicensingException::class);
    $owner->delete();
    expect($batch->fresh()->reseller_user_id)->toBeNull()->and($batch->fresh()->api_client_id)->toBe($client->id)
        ->and($batch->keys->first()->fresh()->revoked_at)->toBeNull();
    $audit = AuditEvent::where('event', 'management_client_retired')->sole();
    expect($audit->before['owner_user_id'])->toBe($owner->id)->and($audit->reason)->toBe('Relationship ended');
    expect(fn () => app(ManagementAccess::class)->authenticate($token))->toThrow(LicensingException::class);
});

test('ownership cannot change for owned or ownerless clients', function (bool $owned) {
    $actor = User::factory()->create();
    [$client] = resellerCredential($owned ? $actor : null);
    $other = User::factory()->create();
    expect(fn () => app(ManageApiClients::class)->save($client, ['owner_user_id' => $other->id], $actor))
        ->toThrow(LicensingException::class, 'management owner immutable');
    if ($owned) {
        expect(fn () => $client->update(['owner_user_id' => null]))->toThrow(LicensingException::class);
    }
    expect($client->fresh()->owner_user_id)->toBe($owned ? $actor->id : null);
})->with([true, false]);

test('client creation validates owners and ignores privileged retirement input', function () {
    $actor = User::factory()->create();
    foreach ([['unexpected'], 999999] as $invalid) {
        expect(fn () => app(ManageApiClients::class)->save(null, ['name' => 'Invalid', 'owner_user_id' => $invalid], $actor))
            ->toThrow(ValidationException::class);
    }
    $client = app(ManageApiClients::class)->save(null, ['name' => 'Owned', 'owner_user_id' => $actor->id, 'retired_at' => now()], $actor);
    expect($client->owner_user_id)->toBe($actor->id)->and($client->retired_at)->toBeNull();
});

test('retirement requires its own policy ability even when management gate allows access', function () {
    $actor = User::factory()->create();
    [$client] = resellerCredential($actor);
    $policy = new class
    {
        public function retire(User $actor, ApiClient $client): bool
        {
            return false;
        }
    };
    Gate::policy(ApiClient::class, $policy::class);
    expect(fn () => app(ManageApiClients::class)->retire($client, $actor, 'Denied'))->toThrow(LicensingException::class);
    expect($client->fresh()->retired_at)->toBeNull()->and($client->tokens()->count())->toBe(1);
});

test('batch attribution cannot be changed through model writes', function () {
    $actor = User::factory()->create();
    $reseller = User::factory()->create();
    $batch = app(CreateBatch::class)->createFor($actor, LicensePlan::factory()->create(), 1, 'sale', reseller: $reseller);
    expect(fn () => $batch->update(['reseller_user_id' => $actor->id]))->toThrow(LicensingException::class, 'batch attribution immutable');
    expect($batch->fresh()->reseller_user_id)->toBe($reseller->id);
});

test('readiness diagnoses a stale schema missing retirement storage', function (string $table, string $column) {
    Schema::table($table, fn ($blueprint) => $blueprint->dropColumn($column));
    $this->artisan('licensekit:check')->expectsOutputToContain('Missing '.$table.' column: '.$column)->assertFailed();
})->with([['licensekit_api_clients', 'retired_at']]);

test('new local issuance rejects nonexistent unverified and incorrectly typed reseller models', function (string $state) {
    $reseller = match ($state) {
        'unverified' => User::factory()->create(['email_verified_at' => null]),
        'missing' => new User(['id' => 999999]),
        'wrong model' => new CustomUser(['id' => User::factory()->create()->id]),
    };
    expect(fn () => app(IssueLicenses::class)->issue('store', LicensePlan::factory()->create()->id, 1, 'invalid', reseller: $reseller))
        ->toThrow(LicensingException::class, 'invalid reseller');
    $this->assertDatabaseCount('licensekit_batches', 0);
})->with(['unverified', 'missing', 'wrong model']);

test('retirement requires fresh authorization and audit failures roll back all changes', function () {
    $owner = User::factory()->create();
    [$client] = resellerCredential($owner);
    Gate::define('licensekit.manage', fn () => false);
    expect(fn () => app(ManageApiClients::class)->retire($client, $owner, 'Denied'))->toThrow(LicensingException::class);
    Gate::define('licensekit.manage', fn () => true);
    expect(fn () => app(ManageApiClients::class)->retire($client, $owner, '  '))->toThrow(ValidationException::class);
    $this->mock(Audit::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('audit failed'));
    expect(fn () => app(ManageApiClients::class)->retire($client, $owner, 'End'))->toThrow(RuntimeException::class, 'audit failed');
    expect($client->fresh()->owner_user_id)->toBe($owner->id)->and($client->fresh()->retired_at)->toBeNull()->and($client->tokens()->count())->toBe(1);
});

test('attributed issuance joins outer transactions', function () {
    $owner = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    expect(fn () => DB::transaction(function () use ($owner, $plan) {
        app(IssueLicenses::class)->issue('store', $plan->id, 1, 'rollback', reseller: $owner);
        throw new RuntimeException('rollback');
    }))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('licensekit_batches', 0);
    $this->assertDatabaseCount('licensekit_license_keys', 0);
    expect(AuditEvent::where('event', 'batch_created')->count())->toBe(0);
});

test('ownership and attribution support configured user tables and key types', function (string $model) {
    config(['auth.providers.users.model' => $model]);
    DB::rollBack();
    $this->artisan('migrate:fresh')->assertSuccessful();
    DB::beginTransaction();
    $owner = $model::create(User::factory()->raw());
    $actor = $model::create(User::factory()->raw());
    $client = ApiClient::factory()->create(['owner_user_id' => $owner->getKey()]);
    $batch = app(CreateBatch::class)->createFor($actor, LicensePlan::factory()->create(), 1, 'typed', reseller: $owner);
    expect((string) $client->owner->getKey())->toBe((string) $owner->getKey())
        ->and((string) $batch->reseller->getKey())->toBe((string) $owner->getKey())
        ->and((string) $batch->creator->getKey())->toBe((string) $actor->getKey());
    expect(app(ResellerInventory::class)->keys($owner, $batch->id)->total())->toBe(1);
    app(ManageApiClients::class)->retire($client, $actor, 'End');
    $owner->delete();
    expect($batch->fresh()->reseller_user_id)->toBeNull();
    $this->artisan('licensekit:check')->assertSuccessful();
})->with([UuidUser::class, UlidUser::class, CustomUser::class]);

test('reseller PHP reads owned batches with licensing counts and explicit paginated key data', function () {
    $owner = User::factory()->create();
    $actor = User::factory()->create();
    $plan = LicensePlan::factory()->create(['duration_days' => 7]);
    $batch = app(CreateBatch::class)->createFor($actor, $plan, 3, 'owned', reseller: $owner);
    $batch->keys[0]->update(['claimed_at' => now(), 'note' => 'private']);
    $batch->keys[1]->update(['revoked_at' => now()]);
    $other = app(CreateBatch::class)->createFor($actor, $plan, 1, 'other', reseller: $actor);
    $plan->update(['duration_days' => 30, 'is_enabled' => false]);
    $inventory = app(ResellerInventory::class);
    $listed = $inventory->batches($owner);
    expect($listed->pluck('id')->all())->toBe([$batch->id]);
    $found = $inventory->batch($owner, $batch->id);
    expect($found->total_count)->toBe(3)->and($found->claimed_count)->toBe(1)
        ->and($found->unclaimed_count)->toBe(1)->and($found->revoked_count)->toBe(1);
    expect($inventory->keys($owner, $batch->id, 1, 2)->items()[0]['id'])->toBe($batch->keys[1]->id);
    foreach (['claimed' => 0, 'revoked' => 1, 'unclaimed' => 2] as $status => $index) {
        $keys = $inventory->keys($owner, $batch->id, status: $status);
        expect($keys->total())->toBe(1)->and($keys->items()[0]['id'])->toBe($batch->keys[$index]->id)
            ->and($keys->items()[0]['duration_days'])->toBe(7)
            ->and(array_keys($keys->items()[0]))->toBe(['id', 'key', 'duration_days', 'claimed_at', 'revoked_at']);
    }
    expect(fn () => $inventory->batch($owner, $other->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => $inventory->keys($owner, $other->id))->toThrow(ModelNotFoundException::class);
    expect($inventory->batches($owner, planId: $plan->id + 1)->total())->toBe(0);
});

test('reseller PHP checks fresh eligibility and configured identity before reads', function (string $state) {
    $owner = User::factory()->create();
    $candidate = $owner;
    match ($state) {
        'suspended' => $owner->update(['is_suspended' => true]),
        'unverified' => $owner->update(['email_verified_at' => null]),
        'deleted' => $owner->delete(),
        'wrong table' => $candidate = new CustomUser(['id' => $owner->id]),
        'wrong connection' => $candidate = (clone $owner)->setConnection('other'),
        'missing id' => $candidate = new User,
    };
    foreach (['batches', 'batch', 'keys'] as $method) {
        expect(fn () => $method === 'batches'
            ? app(ResellerInventory::class)->batches($candidate)
            : app(ResellerInventory::class)->$method($candidate, 1))->toThrow(LicensingException::class, 'user unavailable');
    }
})->with(['suspended', 'unverified', 'deleted', 'wrong table', 'wrong connection', 'missing id']);

test('reseller PHP rejects invalid pagination and key filters', function (int $perPage, int $page, string $status, string $error) {
    $owner = User::factory()->create();
    $batch = app(CreateBatch::class)->createFor($owner, LicensePlan::factory()->create(), 1, 'validation', reseller: $owner);
    expect(fn () => app(ResellerInventory::class)->keys($owner, $batch->id, $perPage, $page, $status))
        ->toThrow(LicensingException::class, $error);
})->with([[0, 1, 'all', 'invalid page size'], [101, 1, 'all', 'invalid page size'], [25, 0, 'all', 'invalid page size'], [25, 1, 'sold', 'invalid key status']]);
