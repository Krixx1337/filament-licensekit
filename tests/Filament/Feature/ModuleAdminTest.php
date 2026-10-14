<?php

use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Actions\Audit;
use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Models\ApiClient;
use LicenseKit\Core\Models\DeviceBinding;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\Resources\ApiClients\Pages\ManageApiClients;
use LicenseKit\Filament\Resources\Compensations\Pages\ManageCompensations;
use LicenseKit\Filament\Resources\Entitlements\Pages\ManageEntitlements;
use LicenseKit\Filament\Resources\LicenseKeys\Pages\ManageLicenseKeys;
use LicenseKit\Filament\Resources\Outages\Pages\ManageOutages;
use LicenseKit\Filament\Resources\Products\Pages\ManageProducts;
use LicenseKit\Modules\Outages\Jobs\ApplyCompensation;
use LicenseKit\Modules\Outages\Models\CompensationEntry;
use LicenseKit\Modules\Outages\Models\LicensingOperation;
use LicenseKit\Modules\Outages\Models\Outage;
use LicenseKit\Tests\Core\Fixtures\ClientSessions;
use LicenseKit\Tests\Filament\Fixtures\UlidAdminUser;
use LicenseKit\Tests\Filament\Fixtures\User;
use LicenseKit\Tests\Filament\Fixtures\UuidAdminUser;
use Livewire\Livewire;

test('optional pages render only on panels registering the plugin', function () {
    $user = adminUser();
    $this->get('/full/licensekit/outages')->assertOk();
    $this->get('/full/licensekit/product-releases')->assertNotFound();
    Filament::setCurrentPanel(Panel::make()->id('unregistered')->authGuard('staff'));
    Livewire::test(ManageOutages::class)->assertForbidden();
});

test('entitlement status uses audited actions without package session termination', function () {
    adminUser();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    Livewire::test(ManageEntitlements::class)->callTableAction('is_blocked', $entitlement, data: ['reason' => 'Support'])->assertHasNoActionErrors();
    expect($entitlement->fresh()->is_blocked)->toBeTrue();
    Livewire::test(ManageEntitlements::class)->assertTableActionDoesNotExist('terminate_session');
    $this->assertDatabaseHas('licensekit_audit_events', ['event' => 'entitlement_status_changed', 'reason' => 'Support']);
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

test('outage administration starts and resumes scoped maintenance', function () {
    adminUser();
    Filament::setCurrentPanel(Filament::getPanel('full'));
    Livewire::test(ManageOutages::class)->assertActionDoesNotExist('add_time')->callAction('start_outage', data: ['product_id' => Product::factory()->create()->id, 'reference' => 'maintenance', 'reason' => 'Deploy', 'pause' => true, 'block' => true])->assertHasNoActionErrors();
    Livewire::test(ManageCompensations::class)->assertActionExists('add_time');
    $outage = Outage::first();
    expect($outage->product_id)->not->toBeNull();
    Livewire::test(ManageOutages::class)->callTableAction('stop_outage', $outage, data: ['compensate' => 'no'])->assertHasNoActionErrors();
    expect($outage->fresh()->ended_at)->not->toBeNull();
});

test('unavailable administrator is rejected before rendering or writing', function () {
    $admin = adminUser();
    $admin->update(['is_suspended' => true]);
    Livewire::test(ManageProducts::class)->assertForbidden();
});

test('string host user IDs survive administrative claims and audit actors', function (string $model) {
    config(['auth.providers.users.model' => $model]);
    $this->app['db']->connection()->rollBack();
    $this->artisan('migrate:fresh')->assertSuccessful();
    $this->app['db']->connection()->beginTransaction();
    $admin = $model::create(['email' => 'admin@example.test', 'password' => 'password', 'email_verified_at' => now()]);
    $customer = $model::create(['email' => 'customer@example.test', 'password' => 'password', 'email_verified_at' => now()]);
    $this->actingAs($admin, 'staff');
    Gate::define('licensekit.manage', fn ($actor): bool => $actor->getKey() === $admin->getKey());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $plan = LicensePlan::factory()->create();
    $key = app(CreateBatch::class)->create($plan, 1, 'sale', 'test')->keys->first();
    Livewire::test(ManageLicenseKeys::class)->callTableAction('claim', $key, data: ['user_id' => $customer->getKey()])->assertHasNoActionErrors();
    $this->assertDatabaseHas('licensekit_entitlements', ['user_id' => $customer->getKey()]);
    $this->assertDatabaseHas('licensekit_audit_events', ['event' => 'key_claimed', 'actor' => 'user:'.$admin->getKey()]);
})->with([[UuidAdminUser::class], [UlidAdminUser::class]]);

test('product outage and custom credit actions target selected product', function () {
    $this->travelTo(now()->startOfSecond());
    adminUser();
    Filament::setCurrentPanel(Filament::getPanel('full'));
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    $original = $entitlement->expires_at;
    $product = $entitlement->product;
    Livewire::test(ManageProducts::class)->callTableAction('add_time', $product,
        data: ['product_id' => $product->id, 'reference' => 'product-compensation', 'reason' => 'Compensation', 'duration' => 1, 'unit' => '86400'])->assertHasNoActionErrors();
    $operation = LicensingOperation::firstOrFail();
    (new ApplyCompensation($operation->id))->handle(app(Audit::class));
    expect($entitlement->fresh()->expires_at->equalTo($original->addDay()))->toBeTrue();
    Livewire::test(ManageProducts::class)->callTableAction('start_outage', $product,
        data: ['product_id' => $product->id, 'reference' => 'product-outage', 'reason' => 'Deploy', 'block' => true])->assertHasNoActionErrors();
    expect(Outage::firstOrFail()->product_id)->toBe($product->id);
});

test('active only credit selects licenses at confirmation and ignores hidden incident date', function () {
    $this->travelTo(now()->startOfSecond());
    adminUser();
    Filament::setCurrentPanel(Filament::getPanel('full'));
    $product = Product::factory()->create();
    $expiring = Entitlement::factory()->create(['user_id' => User::factory()->create()->id, 'product_id' => $product->id, 'expires_at' => now()->addMinute()]);
    $component = Livewire::test(ManageProducts::class)->mountTableAction('add_time', $product)->assertFormFieldHidden('eligible_at');
    $this->travel(2)->minutes();
    $active = Entitlement::factory()->create(['user_id' => User::factory()->create()->id, 'product_id' => $product->id]);

    $component->fillForm(['duration' => 1, 'unit' => '86400', 'reference' => 'active-credit', 'reason' => 'Goodwill', 'eligible_at' => now()->subDay()->format('Y-m-d H:i:s')])->callMountedTableAction()->assertHasNoActionErrors();

    $operation = LicensingOperation::firstOrFail();
    expect($operation->eligible_at->equalTo(now()))->toBeTrue();
    expect(CompensationEntry::where('operation_id', $operation->id)->pluck('entitlement_id')->all())->toBe([$active->id]);
    expect($expiring->fresh()->expires_at->isPast())->toBeTrue();
});

test('incident credit preserves selected cutoff and extends recently expired licenses', function () {
    $this->travelTo(now()->startOfSecond());
    adminUser();
    Filament::setCurrentPanel(Filament::getPanel('full'));
    $product = Product::factory()->create();
    $cutoff = now()->subDay()->format('Y-m-d H:i:s');
    $expired = Entitlement::factory()->create(['user_id' => User::factory()->create()->id, 'product_id' => $product->id, 'created_at' => now()->subDays(2), 'expires_at' => now()->subHours(20)]);
    Entitlement::factory()->create(['user_id' => User::factory()->create()->id, 'product_id' => $product->id]);
    $component = Livewire::test(ManageProducts::class)->mountTableAction('add_time', $product)
        ->fillForm(['targets' => 'incident', 'eligible_at' => $cutoff])->assertFormFieldVisible('eligible_at');
    $this->travel(1)->hours();

    $component->fillForm(['duration' => 1, 'unit' => '86400', 'reference' => 'incident-credit', 'reason' => 'Recovery'])->callMountedTableAction()->assertHasNoActionErrors();

    $operation = LicensingOperation::firstOrFail();
    expect($operation->eligible_at->format('Y-m-d H:i:s'))->toBe($cutoff);
    expect(CompensationEntry::where('operation_id', $operation->id)->pluck('entitlement_id')->all())->toBe([$expired->id]);
    (new ApplyCompensation($operation->id))->handle(app(Audit::class));
    expect($expired->fresh()->expires_at->equalTo(now()->addHours(3)))->toBeTrue();
});

test('incident credit rejects missing and future dates without creating an operation', function (string $date) {
    $this->freezeTime();
    adminUser();
    Filament::setCurrentPanel(Filament::getPanel('full'));
    $product = Product::factory()->create();
    $date = $date === 'future' ? now()->addDay()->format('Y-m-d H:i:s') : $date;

    Livewire::test(ManageProducts::class)->callTableAction('add_time', $product, data: ['targets' => 'incident', 'eligible_at' => $date, 'duration' => 1, 'unit' => '86400', 'reference' => 'invalid-credit', 'reason' => 'Recovery'])->assertHasActionErrors(['eligible_at']);

    $this->assertDatabaseCount('licensekit_compensation_operations', 0);
})->with(['', 'future']);

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
