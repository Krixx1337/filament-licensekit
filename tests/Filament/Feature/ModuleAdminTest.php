<?php

use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Models\ApiClient;
use LicenseKit\Core\Models\DeviceBinding;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\Resources\ApiClients\Pages\ManageApiClients;
use LicenseKit\Filament\Resources\Entitlements\Pages\ManageEntitlements;
use LicenseKit\Filament\Resources\LicenseKeys\Pages\ManageLicenseKeys;
use LicenseKit\Filament\Resources\Products\Pages\ManageProducts;
use LicenseKit\Tests\Core\Fixtures\ClientSessions;
use LicenseKit\Tests\Filament\Fixtures\UlidAdminUser;
use LicenseKit\Tests\Filament\Fixtures\User;
use LicenseKit\Tests\Filament\Fixtures\UuidAdminUser;
use Livewire\Livewire;

test('entitlement status uses audited actions without package session termination', function () {
    adminUser();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    Livewire::test(ManageEntitlements::class)->callTableAction('suspend', $entitlement, data: ['reason' => 'Support'])->assertHasNoActionErrors();
    expect($entitlement->fresh()->isSuspended())->toBeTrue();
    Livewire::test(ManageEntitlements::class)->assertTableActionDoesNotExist('terminate_session');
    $this->assertDatabaseHas('licensekit_audit_events', ['event' => 'license_status_changed', 'reason' => 'Support']);
});

test('claim and key revocation use existing backend rules', function () {
    adminUser();
    $customer = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    $keys = app(CreateBatch::class)->create($plan, 2, 'sale', 'test')->keys;
    Livewire::test(ManageLicenseKeys::class)->callTableAction('claim', $keys[0], data: ['user_id' => $customer->id])->assertHasNoActionErrors();
    $this->assertDatabaseHas('licensekit_entitlements', ['user_id' => $customer->id, 'product_id' => $plan->product_id]);
    Livewire::test(ManageLicenseKeys::class)->callTableAction('revoke_key', $keys[1], data: ['reason' => 'Refund'])->assertHasNoActionErrors();
    expect($keys[1]->fresh()->revoked_at)->not->toBeNull();
});

test('issuance token rotates as private download and can be revoked', function () {
    adminUser();
    $client = ApiClient::factory()->state(['all_products' => true, 'permissions' => ['licenses:create', 'keys:read']])->create();
    Livewire::test(ManageApiClients::class)->callTableAction('rotate_token', $client, data: ['expires_at' => now()->addYear()->format('Y-m-d H:i:s')])->assertFileDownloaded('management-token.txt');
    expect($client->tokens()->count())->toBe(1);
    Livewire::test(ManageApiClients::class)->callTableAction('revoke_tokens', $client)->assertHasNoActionErrors();
    expect($client->tokens()->count())->toBe(0);
});

test('string host user IDs survive administrative claims and audit actors', function (string $model) {
    config(['auth.providers.users.model' => $model]);
    $this->app['db']->connection()->rollBack();
    $this->artisan('migrate:fresh')->assertSuccessful();
    $this->app['db']->connection()->beginTransaction();
    $admin = $model::create(['email' => 'admin@example.test', 'password' => 'password', 'email_verified_at' => now()]);
    $customer = $model::create(['email' => 'customer@example.test', 'password' => 'password', 'email_verified_at' => now()]);
    $this->actingAs($admin, 'staff');
    Gate::define('licensekit.admin', fn ($actor): bool => $actor->getKey() === $admin->getKey());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $plan = LicensePlan::factory()->create();
    $key = app(CreateBatch::class)->create($plan, 1, 'sale', 'test')->keys->first();
    Livewire::test(ManageLicenseKeys::class)->callTableAction('claim', $key, data: ['user_id' => $customer->getKey()])->assertHasNoActionErrors();
    $this->assertDatabaseHas('licensekit_entitlements', ['user_id' => $customer->getKey()]);
    $this->assertDatabaseHas('licensekit_audit_events', ['event' => 'key_claimed', 'actor' => 'user:'.$admin->getKey()]);
})->with([[UuidAdminUser::class], [UlidAdminUser::class]]);

test('HWID reset action invalidates desktop binding and token with support reason', function () {
    adminUser();
    $customer = User::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $customer->id]);
    app(ClientSessions::class)->login($entitlement->product, $customer->email, 'password', ['value' => 'pc']);
    Livewire::test(ManageEntitlements::class)->callTableAction('reset_hwid', $entitlement, data: ['reason' => 'Support request'])->assertHasNoActionErrors();
    expect($entitlement->fresh()->binding->hwid)->toBeNull();
    $this->assertDatabaseCount('client_sessions', 0);
    $this->assertDatabaseHas('licensekit_audit_events', ['event' => 'hwid_reset', 'reason' => 'Support request']);
});

test('backend eligibility failure becomes notification without consuming key', function () {
    adminUser();
    $customer = User::factory()->unverified()->create();
    $plan = LicensePlan::factory()->create();
    $key = app(CreateBatch::class)->create($plan, 1, 'notification-sale', 'test')->keys->first();
    Livewire::test(ManageLicenseKeys::class)->callTableAction('claim', $key, data: ['user_id' => $customer->id])->assertNotified('User unavailable');
    expect($key->fresh()->claimed_at)->toBeNull();
    $this->assertDatabaseCount('licensekit_entitlements', 0);
});

test('entitlement table shows generic binding state with one binding query per render', function (string $scope, bool $paginated, int $quantity) {
    adminUser();
    config(['licensekit.device_binding.scope' => $scope]);
    $product = Product::factory()->create();
    $entitlements = collect(range(1, $quantity))->map(function () use ($product, $scope): Entitlement {
        $user = User::factory()->create();
        DeviceBinding::factory()->create(['user_id' => $user->id, 'scope_key' => $scope === 'global' ? 'global' : 'product:'.$product->id,
            'hwid' => ['ids' => ['board' => 'host-schema'], 'revision' => 7]]);

        return Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    });
    $missing = Entitlement::factory()->create(['user_id' => User::factory()->create()->id, 'product_id' => $product->id]);
    $reset = Entitlement::factory()->create(['user_id' => User::factory()->create()->id, 'product_id' => $product->id]);
    DeviceBinding::factory()->create(['user_id' => $reset->user_id, 'scope_key' => $scope === 'global' ? 'global' : 'product:'.$product->id, 'hwid' => null, 'bound_at' => null]);
    $deleted = Entitlement::factory()->create(['user_id' => User::factory()->create()->id, 'product_id' => $product->id]);
    $deleted->user->delete();
    DB::enableQueryLog();
    DB::flushQueryLog();

    $component = Livewire::test(ManageEntitlements::class);
    if (! $paginated) {
        $component->instance()->getTable()->paginated(false);
        $component->instance()->flushCachedTableRecords();
        DB::flushQueryLog();
    }
    $records = $component->instance()->getTableRecords();
    $component->instance()->getTableRecords();
    $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "licensekit_device_bindings"'));
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1);
    $collection = $records instanceof Collection ? $records : $records->getCollection();
    expect($collection->every(fn (Entitlement $record): bool => $record->relationLoaded('binding')))->toBeTrue();
    $component->assertTableColumnStateSet('device_bound', true, $entitlements->first())
        ->assertTableColumnStateSet('device_bound', false, $missing)
        ->assertTableColumnStateSet('device_bound', false, $reset)
        ->assertTableColumnStateSet('device_bound', false, $deleted)
        ->assertTableColumnStateSet('bound_at', null, $reset)
        ->assertTableColumnDoesNotExist('binding.hwid.contract')
        ->assertTableColumnDoesNotExist('binding.hwid.version');
})->with(['per_product', 'global'])->with([true, false])->with([1, 10]);

test('product add time action previews and extends running licenses', function () {
    adminUser();
    $this->travelTo(now('UTC')->startOfSecond());
    $product = Product::factory()->create();
    $running = Entitlement::factory()->create(['product_id' => $product->id, 'user_id' => User::factory()->create()->id, 'expires_at' => now()->addDay()]);
    $expired = Entitlement::factory()->create(['product_id' => $product->id, 'user_id' => User::factory()->create()->id, 'expires_at' => now()->subHour()]);

    Livewire::test(ManageProducts::class)->mountTableAction('add_time', $product)
        ->assertFormFieldHidden('since')
        ->setTableActionData(['duration' => 2, 'unit' => '3600'])
        ->callMountedTableAction()->assertHasNoTableActionErrors()->assertNotified('Added time to 1 license');

    expect($running->fresh()->expires_at->equalTo(now()->addDay()->addHours(2)))->toBeTrue()
        ->and($expired->fresh()->expires_at->equalTo(now()->subHour()))->toBeTrue();
});

test('product add time action can include recently expired licenses', function () {
    adminUser();
    $this->travelTo(now('UTC')->startOfSecond());
    $product = Product::factory()->create();
    $expired = Entitlement::factory()->create(['product_id' => $product->id, 'user_id' => User::factory()->create()->id, 'expires_at' => now()->subHour()]);

    Livewire::test(ManageProducts::class)->callTableAction('add_time', $product, data: [
        'duration' => 1, 'unit' => '86400', 'include_expired' => true, 'since' => now()->subHours(3)->setTimezone(config('app.timezone'))->format('Y-m-d H:i'),
    ])->assertHasNoTableActionErrors();

    expect($expired->fresh()->expires_at->equalTo(now()->subHour()->addDay()))->toBeTrue();
});
