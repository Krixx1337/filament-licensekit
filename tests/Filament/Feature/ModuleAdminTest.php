<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Models\ApiClient;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\Resources\ApiClients\Pages\ManageApiClients;
use LicenseKit\Filament\Resources\LicenseKeys\Pages\ManageLicenseKeys;
use LicenseKit\Filament\Resources\Licenses\Pages\ManageLicenses;
use LicenseKit\Filament\Resources\Products\Pages\ManageProducts;
use LicenseKit\Tests\Core\Fixtures\ClientSessions;
use LicenseKit\Tests\Filament\Fixtures\UlidAdminUser;
use LicenseKit\Tests\Filament\Fixtures\User;
use LicenseKit\Tests\Filament\Fixtures\UuidAdminUser;
use Livewire\Livewire;

test('license status uses audited actions without package session termination', function () {
    adminUser();
    $license = License::factory()->create(['user_id' => User::factory()->create()->id]);
    Livewire::test(ManageLicenses::class)->callTableAction('suspend', $license, data: ['reason' => 'Support'])->assertHasNoActionErrors();
    expect($license->fresh()->isSuspended())->toBeTrue();
    Livewire::test(ManageLicenses::class)->assertTableActionDoesNotExist('terminate_session');
    $this->assertDatabaseHas('licensekit_audit_events', ['event' => 'license_status_changed', 'reason' => 'Support']);
});

test('claim and key revocation use existing backend rules', function () {
    adminUser();
    $customer = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    $keys = app(CreateBatch::class)->create($plan, 2, 'sale', 'test')->keys;
    Livewire::test(ManageLicenseKeys::class)->callTableAction('claim', $keys[0], data: ['user_id' => $customer->id])->assertHasNoActionErrors();
    $this->assertDatabaseHas('licensekit_licenses', ['user_id' => $customer->id, 'product_id' => $plan->product_id]);
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
    $this->assertDatabaseHas('licensekit_licenses', ['user_id' => $customer->getKey()]);
    $this->assertDatabaseHas('licensekit_audit_events', ['event' => 'key_claimed', 'actor' => 'user:'.$admin->getKey()]);
})->with([[UuidAdminUser::class], [UlidAdminUser::class]]);

test('reset device action clears the device and session with a support note', function () {
    adminUser();
    $customer = User::factory()->create();
    $license = License::factory()->create(['user_id' => $customer->id]);
    app(ClientSessions::class)->login($license->product, $customer->email, 'password', 'pc');
    Livewire::test(ManageLicenses::class)->callTableAction('reset_hwid', $license, data: ['reason' => 'Support request'])->assertHasNoActionErrors();
    expect($license->fresh()->device)->toBeNull();
    $this->assertDatabaseCount('client_sessions', 0);
    $this->assertDatabaseHas('licensekit_audit_events', ['event' => 'device_reset', 'reason' => 'Support request']);
});

test('backend eligibility failure becomes notification without consuming key', function () {
    adminUser();
    $customer = User::factory()->unverified()->create();
    $plan = LicensePlan::factory()->create();
    $key = app(CreateBatch::class)->create($plan, 1, 'notification-sale', 'test')->keys->first();
    Livewire::test(ManageLicenseKeys::class)->callTableAction('claim', $key, data: ['user_id' => $customer->id])->assertNotified('User unavailable');
    expect($key->fresh()->claimed_at)->toBeNull();
    $this->assertDatabaseCount('licensekit_licenses', 0);
});

test('product add time action previews and extends running licenses', function () {
    adminUser();
    $this->travelTo(now('UTC')->startOfSecond());
    $product = Product::factory()->create();
    $running = License::factory()->create(['product_id' => $product->id, 'user_id' => User::factory()->create()->id, 'expires_at' => now()->addDay()]);
    $expired = License::factory()->create(['product_id' => $product->id, 'user_id' => User::factory()->create()->id, 'expires_at' => now()->subHour()]);

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
    $expired = License::factory()->create(['product_id' => $product->id, 'user_id' => User::factory()->create()->id, 'expires_at' => now()->subHour()]);

    Livewire::test(ManageProducts::class)->callTableAction('add_time', $product, data: [
        'duration' => 1, 'unit' => '86400', 'include_expired' => true, 'since' => now()->subHours(3)->setTimezone(config('app.timezone'))->format('Y-m-d H:i'),
    ])->assertHasNoTableActionErrors();

    expect($expired->fresh()->expires_at->equalTo(now()->subHour()->addDay()))->toBeTrue();
});
