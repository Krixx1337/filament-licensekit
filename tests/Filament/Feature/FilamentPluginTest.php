<?php

use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Filament\Resources\ApiClients\ApiClientResource;
use LicenseKit\Filament\Resources\AuditEvents\AuditEventResource;
use LicenseKit\Filament\Resources\Compensations\CompensationResource;
use LicenseKit\Filament\Resources\DeviceResetHistory\DeviceResetHistoryResource;
use LicenseKit\Filament\Resources\Entitlements\EntitlementResource;
use LicenseKit\Filament\Resources\LicenseKeys\LicenseKeyResource;
use LicenseKit\Filament\Resources\LicenseKeys\Pages\ManageLicenseKeys;
use LicenseKit\Filament\Resources\LicensePlans\LicensePlanResource;
use LicenseKit\Filament\Resources\LicensePlans\Pages\ManageLicensePlans;
use LicenseKit\Filament\Resources\Outages\OutageResource;
use LicenseKit\Filament\Resources\Outages\Pages\ManageOutages;
use LicenseKit\Filament\Resources\Products\Pages\ManageProducts;
use LicenseKit\LicenseKitPlugin;
use LicenseKit\Tests\Filament\Fixtures\User;
use Livewire\Livewire;

test('core resources require panel registration and host gate', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'staff')->get('/admin/licensekit/products')->assertForbidden();
    adminUser();
    $this->get('/admin/licensekit/products')->assertOk();
    foreach (['product-groups', 'license-plans', 'license-keys', 'entitlements', 'api-clients', 'audit-events', 'device-reset-history', 'bans'] as $slug) {
        $this->get('/admin/licensekit/'.$slug)->assertOk();
    }
    $this->get('/admin/licensekit/outages')->assertOk();
});

test('product creation validates catalog settings and records panel actor once', function () {
    $admin = adminUser();
    $data = ['name' => 'Software', 'slug' => 'software', 'is_enabled' => true, 'is_blocked' => false];
    Livewire::test(ManageProducts::class)->callAction('create', data: $data)->assertHasNoActionErrors();
    $this->assertDatabaseHas('licensekit_products', ['slug' => 'software']);
    $this->assertDatabaseCount('licensekit_audit_events', 1);
    $this->assertDatabaseHas('licensekit_audit_events', ['actor' => 'user:'.$admin->id, 'event' => 'configuration_created']);
    Livewire::test(ManageProducts::class)->callAction('create', data: [...$data, 'slug' => 'Bad Slug'])->assertHasActionErrors();
    $this->assertDatabaseCount('licensekit_products', 1);
});

test('permission removal after component mount rejects create', function () {
    adminUser();
    $component = Livewire::test(ManageProducts::class)->mountAction('create');
    Gate::define('licensekit.manage', fn () => false);
    $component->callMountedAction()->assertForbidden();
    $this->assertDatabaseCount('licensekit_products', 0);
});

test('key creation action uses package backend', function () {
    adminUser();
    $plan = LicensePlan::factory()->create([]);
    Livewire::test(ManageLicensePlans::class)->callTableAction('create_keys', $plan, data: ['quantity' => 2, 'reference' => 'created', 'download' => false])->assertHasNoActionErrors();
    $this->assertDatabaseCount('licensekit_license_keys', 2);
});

test('optional resources follow global flags and unsupported tenancy is rejected', function () {
    config(['licensekit.modules.outages' => false, 'licensekit.modules.downloads' => false]);
    $core = Panel::make()->id('core')->authGuard('staff')->plugin(LicenseKitPlugin::make());
    config(['licensekit.modules.outages' => true, 'licensekit.modules.downloads' => true]);
    $full = Panel::make()->id('full')->authGuard('staff')->plugin(LicenseKitPlugin::make());
    expect($core->getResources())->toHaveCount(10);
    expect($full->getResources())->toHaveCount(12);
    $tenant = Panel::make()->id('tenant')->tenant(User::class)->plugin(LicenseKitPlugin::make());
    expect($tenant->getResources())->toBe([]);
    expect(fn () => $tenant->getPlugin('licensekit')->boot($tenant))->toThrow(LicensingException::class, 'licensing unavailable');
    expect(EntitlementResource::getNavigationLabel())->toBe('Licenses')
        ->and(LicensePlanResource::getNavigationLabel())->toBe('Plans')
        ->and(LicenseKeyResource::getNavigationLabel())->toBe('Keys')
        ->and(ApiClientResource::getNavigationLabel())->toBe('API clients')
        ->and(AuditEventResource::getNavigationLabel())->toBe('Change log')
        ->and(OutageResource::getNavigationLabel())->toBe('Service down')
        ->and(CompensationResource::getNavigationLabel())->toBe('Added time')
        ->and(DeviceResetHistoryResource::getNavigationLabel())->toBe('HWID reset history');
});

test('unauthenticated requests use host panel login and unverified administrators cannot mount', function () {
    $this->get('/admin/licensekit/products')->assertRedirect('/admin/login');
    $admin = adminUser();
    $admin->update(['email_verified_at' => null]);
    Livewire::test(ManageProducts::class)->assertForbidden();
});

test('issued keys are exported through backend batch with audit identity', function () {
    $admin = adminUser();
    $plan = LicensePlan::factory()->create();
    Livewire::test(ManageLicensePlans::class)->callTableAction('create_keys', $plan, data: ['quantity' => 2, 'reference' => 'manual-sale'])->assertFileDownloaded('license-keys-1.txt');
    $this->assertDatabaseCount('licensekit_license_keys', 2);
    $this->assertDatabaseHas('licensekit_audit_events', ['event' => 'batch_created', 'actor' => 'user:'.$admin->id]);
});

test('admin sees generated batch details', function () {
    $actor = adminUser();
    $plan = LicensePlan::factory()->create();
    $batch = app(CreateBatch::class)->createFor($actor, $plan, 2, 'giveaway', 'October giveaway');
    Livewire::test(ManageLicenseKeys::class)->assertCanSeeTableRecords(Batch::first()->keys)
        ->assertSee('October giveaway');
    $this->assertDatabaseCount('licensekit_license_keys', 2);
});

test('batch labels render as escaped text in the key table', function () {
    adminUser();
    $plan = LicensePlan::factory()->create();
    $label = '<script>alert("giveaway")</script>';
    $batch = app(CreateBatch::class)->create($plan, 1, 'giveaway', 'test', label: $label);
    Livewire::test(ManageLicenseKeys::class)->assertCanSeeTableRecords($batch->keys)
        ->assertSee($label)->assertDontSee($label, escape: false);
});

test('disabled optional tools cannot render through previously registered resources', function () {
    adminUser();
    $panel = Filament::getPanel('full');
    Filament::setCurrentPanel($panel);
    config(['licensekit.modules.outages' => false]);
    Livewire::test(ManageOutages::class)->assertForbidden();
});
