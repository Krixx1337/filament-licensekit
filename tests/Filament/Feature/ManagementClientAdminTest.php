<?php

use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LicenseKit\Core\Actions\Audit;
use LicenseKit\Core\Actions\ManageApiClients;
use LicenseKit\Core\Models\ApiClient;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\Resources\ApiClients\ApiClientResource;
use LicenseKit\Filament\Resources\ApiClients\Pages\ManageApiClients as ManageClientsPage;
use Livewire\Livewire;

test('management clients can be administered with API disabled and audited explicit product permissions', function () {
    $this->freezeTime();
    $admin = adminUser();
    $product = Product::factory()->create();
    $this->get('/admin/licensekit/api-clients')->assertOk()->assertSee('Management API disabled in host configuration.');
    Livewire::test(ManageClientsPage::class)->callAction('create', data: [
        'name' => 'Giveaway bot', 'is_enabled' => true, 'all_products' => false, 'allowed_product_ids' => [(string) $product->id],
        'permissions' => ['licenses:create', 'keys:read'], 'maximum_quantity' => 50,
    ])->assertHasNoActionErrors()->assertFileDownloaded('management-token.txt');
    $client = ApiClient::firstOrFail();
    $token = $client->tokens()->firstOrFail();
    expect($client->allowed_product_ids)->toBe([$product->id])->and($client->maximum_quantity)->toBe(50);
    expect($token->abilities)->toBe(['licensekit:api', 'licenses:create', 'keys:read'])
        ->and($token->expires_at->toIso8601String())->toBe(now('UTC')->addYear()->startOfSecond()->toIso8601String());
    $this->assertDatabaseHas('licensekit_audit_events', ['actor' => 'user:'.$admin->id, 'event' => 'management_client_created']);
    Livewire::test(ManageClientsPage::class)->callTableAction('edit', $client, data: ['name' => 'Changed', 'all_products' => true, 'permissions' => ['keys:read'],
        'allowed_product_ids' => [(string) $product->id], 'maximum_quantity' => 10, 'is_enabled' => false])->assertHasNoActionErrors();
    $fresh = $client->fresh();
    expect($fresh->all_products)->toBeTrue()->and($fresh->is_enabled)->toBeFalse()->and($fresh->allowed_product_ids)->toBe([])
        ->and($fresh->tokens()->firstOrFail()->id)->toBe($token->id);
    $this->assertDatabaseHas('licensekit_audit_events', ['event' => 'management_client_updated']);
});

test('management token rotation downloads secret once and revocation removes only its client tokens', function () {
    $this->freezeTime();
    adminUser();
    $client = ApiClient::factory()->create(['permissions' => ['keys:read']]);
    $old = $client->createToken('licensekit:api', ['licensekit:api', 'keys:read'], now()->addHour())->accessToken;
    $other = ApiClient::factory()->create();
    $unrelated = $other->createToken('licensekit:api', ['licensekit:api'], now()->addHour())->accessToken;
    Livewire::test(ManageClientsPage::class)->callTableAction('rotate_token', $client, data: ['expires_at' => now('UTC')->addDay()->format('Y-m-d H:i:s')])
        ->assertHasNoActionErrors()->assertFileDownloaded('management-token.txt');
    expect($old->fresh())->toBeNull();
    $token = $client->tokens()->firstOrFail();
    expect($token->abilities)->toBe(['licensekit:api', 'keys:read'])->and($token->expires_at->toIso8601String())->toBe(now()->addDay()->toIso8601String());
    Livewire::test(ManageClientsPage::class)->callTableAction('revoke_tokens', $client)->assertHasNoActionErrors();
    expect($client->tokens()->count())->toBe(0)->and($unrelated->fresh())->not->toBeNull();
    $this->assertDatabaseHas('licensekit_audit_events', ['event' => 'management_tokens_revoked']);
});

test('management client create downloads a one year token and stores no products by default', function () {
    $this->freezeTime();
    adminUser();
    Livewire::test(ManageClientsPage::class)->callAction('create', data: ['name' => 'Unconfigured'])->assertHasNoActionErrors()->assertFileDownloaded('management-token.txt');
    $client = ApiClient::firstOrFail();
    $token = $client->tokens()->firstOrFail();
    expect($client->permissions)->toBe([])->and($client->allowed_product_ids)->toBe([])
        ->and($client->all_products)->toBeFalse()->and($token->abilities)->toBe(['licensekit:api'])
        ->and($token->expires_at->toIso8601String())->toBe(now('UTC')->addYear()->startOfSecond()->toIso8601String());
    expect(ApiClientResource::canDelete($client))->toBeFalse();
});

test('client create edit rotation and revocation reject permission removal after mounting', function (string $operation) {
    adminUser();
    $client = ApiClient::factory()->create();
    $client->createToken('licensekit:api', ['licensekit:api'], now()->addHour());
    $component = Livewire::test(ManageClientsPage::class);
    if ($operation === 'create') {
        $component->mountAction('create');
    } else {
        $component->mountTableAction($operation, $client);
    }
    Gate::define('licensekit.admin', fn () => false);
    if ($operation === 'create') {
        $component->callMountedAction()->assertForbidden();
    } else {
        $component->callMountedTableAction()->assertForbidden();
    }
    $this->assertDatabaseCount('licensekit_api_clients', 1);
    expect($client->tokens()->count())->toBe(1);
})->with(['create', 'edit', 'rotate_token', 'revoke_tokens']);

test('client forms reject unsupported permissions invalid quantities and render names escaped', function () {
    adminUser();
    Livewire::test(ManageClientsPage::class)->callAction('create', data: ['name' => 'Bad', 'maximum_quantity' => 1001])->assertHasActionErrors(['maximum_quantity']);
    $name = '<script>alert("client")</script>';
    $client = ApiClient::factory()->create(['name' => $name]);
    Livewire::test(ManageClientsPage::class)->assertSee($name)->assertDontSee($name, escape: false)->searchTable('alert')->assertCanSeeTableRecords([$client]);
    expect(fn () => $client->update(['permissions' => ['*']]))->toThrow(ValidationException::class);
});

test('failed management client audit rolls back privilege edits', function () {
    $admin = adminUser();
    $client = ApiClient::factory()->create();
    $this->mock(Audit::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('audit failed'));
    expect(fn () => app(ManageApiClients::class)->save($client, ['all_products' => true], $admin))->toThrow(RuntimeException::class);
    expect($client->fresh()->all_products)->toBeFalse();
});
