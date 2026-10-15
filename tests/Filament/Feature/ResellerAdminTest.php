<?php

use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Actions\ManagementOperations;
use LicenseKit\Core\Models\ApiClient;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Filament\Resources\ApiClients\Pages\ManageApiClients;
use LicenseKit\Filament\Resources\Batches\Pages\ManageBatches;
use LicenseKit\Filament\Resources\LicensePlans\Pages\ManageLicensePlans;
use LicenseKit\Tests\Filament\Fixtures\User;
use Livewire\Livewire;

test('staff can create an owned client filter by owner and permanently retire it', function () {
    adminUser();
    $owner = User::factory()->create();
    Livewire::test(ManageApiClients::class)->callAction('create', data: ['name' => 'Reseller bot', 'owner_user_id' => $owner->id])
        ->assertHasNoActionErrors()->assertFileDownloaded('management-token.txt');
    $client = ApiClient::sole();
    $other = ApiClient::factory()->create();
    expect($client->owner_user_id)->toBe($owner->id);
    Livewire::test(ManageApiClients::class)->filterTable('owner_user_id', $owner->id)
        ->assertCanSeeTableRecords([$client])->assertCanNotSeeTableRecords([$other]);
    Livewire::test(ManageApiClients::class)->callTableAction('edit', $client, data: ['name' => 'Renamed', 'owner_user_id' => null])
        ->assertHasNoActionErrors();
    expect($client->fresh()->owner_user_id)->toBe($owner->id);
    Livewire::test(ManageApiClients::class)->callTableAction('retire', $client, data: ['reason' => 'Partnership ended'])
        ->assertHasNoActionErrors();
    expect($client->fresh()->owner_user_id)->toBeNull()->and($client->fresh()->retired_at)->not->toBeNull()->and($client->tokens()->count())->toBe(0);
    Livewire::test(ManageApiClients::class)->assertTableActionHidden('retire', $client)->assertTableActionHidden('rotate_token', $client);
});

test('retirement rechecks staff permission after action mounting', function () {
    adminUser();
    $owner = User::factory()->create();
    $client = ApiClient::factory()->create(['owner_user_id' => $owner->id]);
    $page = Livewire::test(ManageApiClients::class)->mountTableAction('retire', $client);
    Gate::define('licensekit.manage', fn () => false);
    $page->callMountedTableAction(['reason' => 'Denied'])->assertForbidden();
    expect($client->fresh()->retired_at)->toBeNull()->and($client->fresh()->owner_user_id)->toBe($owner->id);
});

test('staff can attribute manual batches and filter manual and API batches by reseller', function () {
    $actor = adminUser();
    $reseller = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    Livewire::test(ManageLicensePlans::class)->callTableAction('create_keys', $plan, data: [
        'quantity' => 1, 'reference' => 'manual', 'reseller_user_id' => $reseller->id, 'download' => false,
    ])->assertHasNoActionErrors();
    $manual = Batch::sole();
    expect($manual->created_by_user_id)->toBe($actor->id)->and($manual->reseller_user_id)->toBe($reseller->id);
    $retail = app(CreateBatch::class)->createFor($actor, $plan, 1, 'retail');
    $client = ApiClient::factory()->create(['owner_user_id' => $reseller->id, 'all_products' => true, 'permissions' => ['licenses:create']]);
    $token = $client->createToken('licensekit:api', ['licensekit:api', 'licenses:create'], now()->addHour())->plainTextToken;
    $api = app(ManagementOperations::class)->create($token, $plan->id, 1, 'api');
    Livewire::test(ManageBatches::class)->filterTable('reseller_user_id', $reseller->id)
        ->assertCanSeeTableRecords([$manual, $api])->assertCanNotSeeTableRecords([$retail]);
    Livewire::test(ManageBatches::class)->filterTable('api_client_id', $client->id)
        ->assertCanSeeTableRecords([$api])->assertCanNotSeeTableRecords([$manual, $retail]);
});
