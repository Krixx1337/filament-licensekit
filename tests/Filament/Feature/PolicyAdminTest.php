<?php

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Actions\DeviceBindings;
use LicenseKit\Core\Actions\ManageApiClients;
use LicenseKit\Core\Actions\ManageEntitlements;
use LicenseKit\Core\Actions\ManageLicensingBans;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\ApiClient;
use LicenseKit\Core\Models\AuditEvent;
use LicenseKit\Core\Models\DeviceBinding;
use LicenseKit\Core\Models\DeviceResetHistory;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\LicensingBan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\CatalogWrites;
use LicenseKit\Filament\Resources\AuditEvents\Pages\ManageAuditEvents;
use LicenseKit\Filament\Resources\DeviceResetHistory\Pages\ManageDeviceResetHistory;
use LicenseKit\Filament\Resources\Entitlements\Pages\ManageEntitlements as EntitlementsPage;
use LicenseKit\Filament\Resources\LicensePlans\Pages\ManageLicensePlans;
use LicenseKit\Filament\Resources\Outages\OutageResource;
use LicenseKit\Filament\Resources\Products\Pages\ManageProducts;
use LicenseKit\Filament\Resources\Products\ProductResource;
use LicenseKit\LicenseKitPlugin;
use LicenseKit\Modules\Outages\Actions\ManageOutages;
use LicenseKit\Modules\Outages\Jobs\ApplyCompensation;
use LicenseKit\Modules\Outages\Models\LicensingOperation;
use LicenseKit\Modules\Outages\Models\Outage;
use LicenseKit\Tests\Core\Fixtures\AdminPolicy;
use LicenseKit\Tests\Filament\Fixtures\User;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

function registerAdminPolicies(array $abilities): void
{
    foreach ([Product::class, Provider::class, LicensePlan::class, LicenseKey::class, Entitlement::class,
        LicensingBan::class, ApiClient::class, AuditEvent::class, DeviceResetHistory::class, Outage::class, LicensingOperation::class] as $model) {
        Gate::policy($model, AdminPolicy::class);
    }
    config(['testing.admin_abilities' => $abilities]);
}

test('policy moderator browses licenses resets paid and free devices and manages bans without master gate', function () {
    $actor = adminUser();
    Gate::define('licensekit.manage', fn () => false);
    registerAdminPolicies(['viewAny', 'view', 'resetHwid', 'inspectDevice', 'create', 'lift']);
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    DeviceBinding::factory()->create(['user_id' => $user->id, 'scope_key' => 'product:'.$product->id]);
    config(['testing.admin_target_id' => $user->id]);
    $this->get('/admin/licensekit/entitlements')->assertOk();
    Livewire::test(EntitlementsPage::class)->callTableAction('reset_hwid', $entitlement, data: ['reason' => 'Support'])->assertHasNoActionErrors();
    $free = User::factory()->create();
    $binding = DeviceBinding::factory()->create(['user_id' => $free->id, 'scope_key' => 'product:'.$product->id]);
    config(['testing.admin_target_id' => $free->id]);
    app(DeviceBindings::class)->adminReset($free, $product, $actor, 'Free user support');
    expect($binding->fresh()->hwid)->toBeNull();
    $ban = app(ManageLicensingBans::class)->create($user, $product, 'Abuse', $actor);
    app(ManageLicensingBans::class)->lift($ban, 'Appeal', $actor);
    expect($ban->fresh()->lifted_at)->not->toBeNull();
    $this->assertDatabaseCount('licensekit_device_reset_history', 2);
    Livewire::test(EntitlementsPage::class)->assertTableActionHidden('is_revoked', $entitlement)->assertTableActionHidden('is_blocked', $entitlement);
    // Browse rights are configured per model in real host policies.
    Gate::policy(LicenseKey::class, DeniedStaffPolicy::class);
    Gate::policy(ApiClient::class, DeniedStaffPolicy::class);
    $this->get('/admin/licensekit/license-keys')->assertForbidden();
    $this->get('/admin/licensekit/api-clients')->assertForbidden();
});

class DeniedStaffPolicy
{
    public function viewAny(User $actor): bool
    {
        return false;
    }
}

test('all admin mutations deny policy refusal even when master gate allows', function (string $operation) {
    $actor = adminUser();
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    $product = $plan->product;
    $entitlement = Entitlement::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $binding = DeviceBinding::factory()->create(['user_id' => $user->id, 'scope_key' => 'product:'.$product->id]);
    $key = app(CreateBatch::class)->createFor($actor, $plan, 1, 'fixture')->keys->first();
    $client = ApiClient::factory()->create();
    $token = $client->createToken('existing')->accessToken;
    $ban = app(ManageLicensingBans::class)->create($user, $product, 'Fixture', $actor);
    $outage = app(ManageOutages::class)->start($product, 'fixture-outage', 'Fixture', $actor);
    $outage->operation->update(['status' => 'failed']);
    registerAdminPolicies([]);
    $audits = AuditEvent::count();
    Queue::fake([ApplyCompensation::class]);
    $call = match ($operation) {
        'createKeys' => fn () => app(CreateBatch::class)->createFor($actor, $plan, 1, 'denied'),
        'claim' => fn () => app(ClaimLicense::class)->claimFor($actor, $user, $key),
        'revoke_key' => fn () => app(ManageEntitlements::class)->revokeKey($key, $actor, 'Denied'),
        'revoke' => fn () => app(ManageEntitlements::class)->status($entitlement, 'is_revoked', true, $actor, 'Denied'),
        'restoreAccess' => fn () => app(ManageEntitlements::class)->status($entitlement, 'is_revoked', false, $actor, 'Denied'),
        'block' => fn () => app(ManageEntitlements::class)->status($entitlement, 'is_blocked', true, $actor, 'Denied'),
        'unblock' => fn () => app(ManageEntitlements::class)->status($entitlement, 'is_blocked', false, $actor, 'Denied'),
        'reset' => fn () => app(DeviceBindings::class)->adminReset($user, $product, $actor, 'Denied'),
        'create_ban' => fn () => app(ManageLicensingBans::class)->create($user, $product, 'Denied', $actor),
        'lift_ban' => fn () => app(ManageLicensingBans::class)->lift($ban, 'Denied', $actor),
        'create_client' => fn () => app(ManageApiClients::class)->save(null, ['name' => 'Denied'], $actor),
        'update_client' => fn () => app(ManageApiClients::class)->save($client, ['name' => 'Denied'], $actor),
        'rotate_token' => fn () => app(ManageApiClients::class)->rotate($client, CarbonImmutable::now()->addYear(), $actor),
        'revoke_tokens' => fn () => app(ManageApiClients::class)->revoke($client, $actor),
        'start_outage' => fn () => app(ManageOutages::class)->start($product, 'denied', 'Denied', $actor),
        'stop_outage' => fn () => app(ManageOutages::class)->stop($outage, false, $actor),
        'preview' => fn () => app(ManageOutages::class)->preview($product, CarbonImmutable::now(), $actor),
        'add_time' => fn () => app(ManageOutages::class)->addTime($product, 60, CarbonImmutable::now(), 'denied', 'Denied', $actor),
        'retry' => fn () => app(ManageOutages::class)->retry($outage->operation, $actor),
        'cancel' => fn () => app(ManageOutages::class)->cancel($outage->operation, $actor),
        'create_catalog' => fn () => CatalogWrites::create(Product::class, ['name' => 'Denied', 'slug' => 'denied'], $actor),
        'update_catalog' => fn () => CatalogWrites::update($product, ['name' => 'Denied'], $actor),
    };
    expect($call)->toThrow(LicensingException::class);
    expect(AuditEvent::count())->toBe($audits)
        ->and($key->fresh()->claimed_at)->toBeNull()->and($key->fresh()->revoked_at)->toBeNull()
        ->and($binding->fresh()->hwid)->not->toBeNull()
        ->and($ban->fresh()->lifted_at)->toBeNull()
        ->and($token->fresh())->not->toBeNull()->and($outage->fresh()->stopped_at)->toBeNull()
        ->and($outage->operation->fresh()->status)->toBe('failed')
        ->and($entitlement->fresh()->is_revoked)->toBeFalse()->and($entitlement->fresh()->is_blocked)->toBeFalse();
    $this->assertDatabaseCount('licensekit_license_keys', 1);
    $this->assertDatabaseCount('licensekit_api_clients', 1);
    Queue::assertNothingPushed();
})->with(['createKeys', 'claim', 'revoke_key', 'revoke', 'restoreAccess', 'block', 'unblock', 'reset',
    'create_ban', 'lift_ban', 'create_client', 'update_client',
    'rotate_token', 'revoke_tokens', 'start_outage', 'stop_outage', 'preview', 'add_time', 'retry', 'cancel',
    'create_catalog', 'update_catalog']);

test('policy permission loss after modal mount refuses creation without affecting resource browsing', function () {
    adminUser();
    registerAdminPolicies(['viewAny', 'createKeys']);
    $plan = LicensePlan::factory()->create();
    $component = Livewire::test(ManageLicensePlans::class)->mountTableAction('create_keys', $plan)->setTableActionData(['quantity' => 2]);
    config(['testing.admin_abilities' => ['viewAny']]);
    $component->callMountedTableAction();
    $this->assertDatabaseCount('licensekit_license_keys', 0);
    $this->get('/admin/licensekit/license-plans')->assertOk();
});

test('policy response methods protect built in CRUD and unsupported operations stay denied', function () {
    adminUser();
    registerAdminPolicies(['viewAny']);
    $product = Product::factory()->create();
    expect(ProductResource::getCreateAuthorizationResponse()->denied())->toBeTrue()
        ->and(ProductResource::getEditAuthorizationResponse($product)->denied())->toBeTrue();
    Livewire::test(ManageProducts::class)->assertActionHidden('create')->assertTableActionHidden('edit', $product);
    config(['testing.admin_abilities' => ['viewAny', 'create', 'update', 'delete']]);
    expect(ProductResource::getCreateAuthorizationResponse()->allowed())->toBeTrue()
        ->and(ProductResource::getDeleteAuthorizationResponse($product)->denied())->toBeTrue()
        ->and(ProductResource::getReplicateAuthorizationResponse($product)->denied())->toBeTrue();
});

test('device inspection requires product permission independently of entitlement browsing', function () {
    adminUser();
    registerAdminPolicies(['viewAny']);
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    DeviceBinding::factory()->create(['user_id' => $entitlement->user_id, 'scope_key' => 'product:'.$entitlement->product_id]);

    Livewire::test(EntitlementsPage::class)->assertTableActionHidden('inspect_device', $entitlement);
    config(['testing.admin_abilities' => ['viewAny', 'inspectDevice']]);
    Livewire::test(EntitlementsPage::class)->mountTableAction('inspect_device', $entitlement)->assertHasNoTableActionErrors();
});

test('exact abilities independently permit key creation and administrative claiming', function () {
    $actor = adminUser();
    Gate::define('licensekit.manage', fn () => false);
    registerAdminPolicies(['viewAny']);
    $plan = LicensePlan::factory()->create();
    expect(fn () => app(CreateBatch::class)->createFor($actor, $plan, 1, 'sale'))->toThrow(LicensingException::class);
    config(['testing.admin_abilities' => ['createKeys']]);
    $batch = app(CreateBatch::class)->createFor($actor, $plan, 1, 'sale');
    expect($batch->keys)->toHaveCount(1);
    $user = User::factory()->create();
    expect(fn () => app(ClaimLicense::class)->claimFor($actor, $user, $batch->keys[0]))->toThrow(LicensingException::class);
    config(['testing.admin_abilities' => ['claim']]);
    $entitlement = app(ClaimLicense::class)->claimFor($actor, $user, $batch->keys[0]);
    expect($entitlement->user_id)->toBe($user->id);
    config(['testing.admin_abilities' => ['revoke']]);
    $key = LicenseKey::factory()->create(['license_plan_id' => $plan->id]);
    app(ManageEntitlements::class)->revokeKey($key, $actor, 'Unused');
    expect($key->fresh()->revoked_at)->not->toBeNull();
});

test('entitlement status changes use distinct exact abilities', function (string $field, bool $value, string $ability) {
    $actor = adminUser();
    registerAdminPolicies([$ability]);
    Gate::define('licensekit.manage', fn () => false);
    $entitlement = Entitlement::factory()->create([$field => ! $value]);
    app(ManageEntitlements::class)->status($entitlement, $field, $value, $actor, 'Support');
    expect($entitlement->fresh()->getAttribute($field))->toBe($value);
})->with([
    ['is_revoked', true, 'revoke'], ['is_revoked', false, 'restoreAccess'],
    ['is_blocked', true, 'block'], ['is_blocked', false, 'unblock'],
]);

test('credential create update rotate and revoke use separate exact abilities', function () {
    $actor = adminUser();
    Gate::define('licensekit.manage', fn () => false);
    registerAdminPolicies(['create']);
    $service = app(ManageApiClients::class);
    $client = $service->save(null, ['name' => 'Integration'], $actor);
    config(['testing.admin_abilities' => ['update']]);
    $service->save($client, ['name' => 'Edited'], $actor);
    config(['testing.admin_abilities' => ['rotateToken']]);
    $plaintext = $service->rotate($client, CarbonImmutable::now()->addYear(), $actor);
    expect($plaintext)->not->toBeEmpty();
    config(['testing.admin_abilities' => ['revokeTokens']]);
    $service->revoke($client, $actor);
    expect($client->fresh()->name)->toBe('Edited')->and($client->tokens()->count())->toBe(0);
});

test('catalog create and update use model policies without panel dependency', function () {
    $actor = adminUser();
    Gate::define('licensekit.manage', fn () => false);
    registerAdminPolicies(['create']);
    $product = CatalogWrites::create(Product::class, ['name' => 'Product', 'slug' => 'product'], $actor);
    config(['testing.admin_abilities' => ['update']]);
    CatalogWrites::update($product, ['name' => 'Edited'], $actor);
    expect($product->fresh()->name)->toBe('Edited');
});

test('outage start stop preview credit retry and cancel use their exact policy abilities', function () {
    $actor = adminUser();
    Gate::define('licensekit.manage', fn () => false);
    registerAdminPolicies(['startOutage']);
    $product = Product::factory()->create();
    $service = app(ManageOutages::class);
    $outage = $service->start($product, 'incident', 'Maintenance', $actor);
    config(['testing.admin_abilities' => ['stop']]);
    $service->stop($outage, false, $actor);
    expect($outage->fresh()->ended_at)->not->toBeNull();
    config(['testing.admin_abilities' => ['addTime']]);
    expect($service->preview($product, CarbonImmutable::now(), $actor))->toBe(0);
    Queue::fake([ApplyCompensation::class]);
    $operation = $service->addTime($product, 60, CarbonImmutable::now(), 'bonus', 'Goodwill', $actor);
    $operation->update(['status' => 'failed']);
    config(['testing.admin_abilities' => ['retry']]);
    $service->retry($operation, $actor);
    expect($operation->fresh()->status)->toBe('pending');
    config(['testing.admin_abilities' => ['cancel']]);
    $service->cancel($operation, $actor);
    expect($operation->fresh()->status)->toBe('canceled');
    Queue::assertPushed(ApplyCompensation::class, 2);
});

test('device policies receive target user and reject another record or stale suspended actor', function () {
    $actor = adminUser();
    registerAdminPolicies(['viewAny', 'resetHwid', 'inspectDevice']);
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $otherProduct = Product::factory()->create();
    $binding = DeviceBinding::factory()->create(['user_id' => $user->id, 'scope_key' => 'product:'.$product->id]);
    $otherBinding = DeviceBinding::factory()->create(['user_id' => $user->id, 'scope_key' => 'product:'.$otherProduct->id]);
    config(['testing.admin_record_id' => $product->id, 'testing.admin_target_id' => $user->id]);
    expect(fn () => app(DeviceBindings::class)->adminReset($user, $otherProduct, $actor, 'Denied'))->toThrow(LicensingException::class);
    $otherUser = User::factory()->create();
    DeviceBinding::factory()->create(['user_id' => $otherUser->id, 'scope_key' => 'product:'.$product->id]);
    expect(fn () => app(DeviceBindings::class)->adminReset($otherUser, $product, $actor, 'Denied'))->toThrow(LicensingException::class);
    $actor->newQuery()->whereKey($actor->id)->update(['is_suspended' => true]);
    expect(fn () => app(DeviceBindings::class)->adminReset($user, $product, $actor, 'Denied'))->toThrow(LicensingException::class);
    expect($binding->fresh()->hwid)->not->toBeNull()->and($otherBinding->fresh()->hwid)->not->toBeNull();
    $this->assertDatabaseCount('licensekit_device_reset_history', 0);
});

test('installation diagnostics accept complete policies without master Gate', function () {
    registerAdminPolicies([]);
    expect(Gate::has('licensekit.manage'))->toBeFalse();
    $this->artisan('licensekit:check')->assertSuccessful();
});

test('resource browsing does not expose denied audit or reset history details', function () {
    $actor = adminUser();
    $user = User::factory()->create();
    $product = Product::factory()->create();
    DeviceBinding::factory()->create(['user_id' => $user->id, 'scope_key' => 'product:'.$product->id]);
    app(DeviceBindings::class)->adminReset($user, $product, $actor, 'Support');
    registerAdminPolicies(['viewAny']);
    Livewire::test(ManageAuditEvents::class)
        ->assertTableActionHidden('details', AuditEvent::first());
    Livewire::test(ManageDeviceResetHistory::class)->filterTable('user_id', (string) $user->id)
        ->assertTableActionHidden('details', DeviceResetHistory::first());
    config(['testing.admin_abilities' => ['viewAny', 'view']]);
    Livewire::test(ManageDeviceResetHistory::class)->filterTable('user_id', (string) $user->id)
        ->mountTableAction('details', DeviceResetHistory::first())
        ->assertTableActionDataSet(['reason' => 'Support']);
});

test('display checks refresh actor once while policies and resource eligibility remain live', function () {
    adminUser();
    registerAdminPolicies(['viewAny']);
    app()->instance('request', Request::create('/admin/licensekit/products'));
    DB::enableQueryLog();
    DB::flushQueryLog();

    foreach (range(1, 25) as $unused) {
        expect(AdminAccess::can('viewAny', Product::class, ProductResource::class))->toBeTrue();
    }
    $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "users"'));
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1);
    config(['testing.admin_abilities' => []]);
    expect(AdminAccess::can('viewAny', Product::class, ProductResource::class))->toBeFalse();
    expect(AdminAccess::allowed('UnregisteredResource'))->toBeFalse();
    config(['licensekit.modules.outages' => false]);
    expect(AdminAccess::allowed(OutageResource::class))->toBeFalse();
    Filament::setCurrentPanel(Panel::make()->id('no-plugin')->authGuard('staff'));
    expect(AdminAccess::allowed())->toBeFalse();
});

test('sensitive actor resolution rejects stale display permission', function (string $condition) {
    $actor = adminUser();
    app()->instance('request', Request::create('/admin'));
    expect(AdminAccess::can('viewAny', Product::class))->toBeTrue();
    match ($condition) {
        'suspended' => $actor->newQuery()->whereKey($actor->id)->update(['is_suspended' => true]),
        'deleted' => $actor->delete(),
        'unverified' => $actor->newQuery()->whereKey($actor->id)->update(['email_verified_at' => null]),
        'permission' => Gate::define('licensekit.manage', fn () => false),
    };

    expect(fn () => AdminAccess::authorize('viewAny', Product::class))->toThrow(HttpException::class);
    if ($condition !== 'permission') {
        expect(fn () => AdminAccess::actor())->toThrow(HttpException::class);
        expect(AdminAccess::allowed())->toBeFalse();
    }
})->with(['suspended', 'deleted', 'unverified', 'permission']);

test('actor cache follows request panel guard and user identity', function () {
    $actor = adminUser();
    app()->instance('request', Request::create('/admin'));
    expect(AdminAccess::allowed())->toBeTrue();
    $actor->newQuery()->whereKey($actor->id)->update(['is_suspended' => true]);
    expect(AdminAccess::allowed())->toBeTrue();
    Filament::setCurrentPanel(Filament::getPanel('full'));
    expect(AdminAccess::allowed())->toBeFalse();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $other = User::factory()->create(['is_suspended' => true]);
    $this->actingAs($other, 'staff');
    expect(AdminAccess::allowed())->toBeFalse();
    $this->actingAs($actor, 'staff');
    expect(AdminAccess::allowed())->toBeTrue();
    $this->actingAs($other, 'web');
    Filament::setCurrentPanel(Panel::make()->id('other-guard')->authGuard('web')->plugin(LicenseKitPlugin::make()));
    expect(AdminAccess::allowed())->toBeFalse();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    app()->instance('request', Request::create('/admin'));
    expect(AdminAccess::allowed())->toBeFalse();
});

test('missing cached actor remains denied until fresh resolution replaces it', function () {
    $actor = adminUser();
    app()->instance('request', Request::create('/admin'));
    $actor->newQuery()->whereKey($actor->id)->delete();
    expect(AdminAccess::allowed())->toBeFalse();
    DB::enableQueryLog();
    DB::flushQueryLog();

    expect(AdminAccess::allowed())->toBeFalse();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toBeEmpty();
    User::factory()->create(['id' => $actor->id]);
    expect(AdminAccess::allowed())->toBeFalse();
    expect(AdminAccess::actor()->getKey())->toBe($actor->id);
    expect(AdminAccess::allowed())->toBeTrue();
});
