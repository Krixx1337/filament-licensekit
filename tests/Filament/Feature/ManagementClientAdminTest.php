<?php

use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Models\ActivityEntry;
use LicenseKit\Core\Models\ApiConnection;
use LicenseKit\Filament\Resources\ApiConnections\Pages\ManageApiConnections;
use Livewire\Livewire;

test('connection creation shows token once in modal and stores only hash', function () {
    adminUser();
    $page = Livewire::test(ManageApiConnections::class)->callAction('create', data: [
        'name' => 'Store', 'is_active' => true, 'all_products' => true, 'allowed_product_ids' => [], 'max_per_request' => 25,
    ])->assertHasNoErrors()->assertNoFileDownloaded()->assertSet('mountedActions.0.name', 'result');
    $connection = ApiConnection::sole();
    expect($connection->token_hash)->toHaveLength(64);
    expect($connection->allowed_product_ids)->toBeNull();
    expect(ActivityEntry::all()->toJson())->not->toContain($connection->token_hash);
});

test('regeneration replaces token and reauthorizes after modal opens', function () {
    adminUser();
    $connection = ApiConnection::factory()->create();
    Livewire::test(ManageApiConnections::class)->callTableAction('regenerate_token', $connection)->assertHasNoErrors()->assertSet('mountedActions.0.name', 'result');
    $hash = $connection->fresh()->token_hash;
    $page = Livewire::test(ManageApiConnections::class)->mountTableAction('regenerate_token', $connection);
    Gate::define('licensekit.admin', fn () => false);
    $page->callMountedTableAction()->assertForbidden();
    expect($connection->fresh()->token_hash)->toBe($hash);
});

test('connection forms escape names and validate request cap', function () {
    adminUser();
    $connection = ApiConnection::factory()->create(['name' => '<script>bad</script>']);
    Livewire::test(ManageApiConnections::class)->assertSee('&lt;script&gt;bad&lt;/script&gt;', false)->assertDontSee('<script>bad</script>', false);
    Livewire::test(ManageApiConnections::class)->callTableAction('edit', $connection, data: [
        'name' => 'Store', 'is_active' => true, 'all_products' => false, 'allowed_product_ids' => [], 'max_per_request' => 0,
    ])->assertHasActionErrors(['max_per_request']);
});
