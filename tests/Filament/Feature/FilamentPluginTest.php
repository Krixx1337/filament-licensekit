<?php

use Filament\Panel;
use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Filament\Resources\ApiClients\ApiClientResource;
use LicenseKit\Filament\Resources\AuditEvents\AuditEventResource;
use LicenseKit\Filament\Resources\Entitlements\EntitlementResource;
use LicenseKit\Filament\Resources\LicenseKeys\LicenseKeyResource;
use LicenseKit\Filament\Resources\LicenseKeys\Pages\ManageLicenseKeys;
use LicenseKit\Filament\Resources\LicensePlans\LicensePlanResource;
use LicenseKit\Filament\Resources\LicensePlans\Pages\ManageLicensePlans;
use LicenseKit\Filament\Resources\Products\Pages\ManageProducts;
use LicenseKit\LicenseKitPlugin;
use LicenseKit\Tests\Filament\Fixtures\User;
use Livewire\Livewire;

test('core resources require panel registration and host gate', function () {
    $user = User::factory()->create();
    Gate::define('licensekit.admin', fn (): bool => false);
    $this->actingAs($user, 'staff')->get('/admin/licensekit/products')->assertForbidden();
    adminUser();
    $this->get('/admin/licensekit/products')->assertOk();
    foreach (['license-plans', 'license-keys', 'entitlements', 'api-clients', 'audit-events'] as $slug) {
        $this->get('/admin/licensekit/'.$slug)->assertOk();
    }
});

test('product creation validates catalog settings and records panel actor once', function () {
    $admin = adminUser();
    $data = ['name' => 'Software', 'slug' => 'software', 'status' => 'active'];
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
    Gate::define('licensekit.admin', fn () => false);
    $component->callMountedAction()->assertForbidden();
    $this->assertDatabaseCount('licensekit_products', 0);
});

test('key creation action uses package backend', function () {
    adminUser();
    $plan = LicensePlan::factory()->create([]);
    Livewire::test(ManageLicensePlans::class)->callTableAction('create_keys', $plan, data: ['quantity' => 2, 'reference' => 'created', 'download' => false])->assertHasNoActionErrors();
    $this->assertDatabaseCount('licensekit_license_keys', 2);
});

test('resources register on non-tenant panels and unsupported tenancy is rejected', function () {
    $core = Panel::make()->id('core')->authGuard('staff')->plugin(LicenseKitPlugin::make());
    expect($core->getResources())->toHaveCount(7);
    $tenant = Panel::make()->id('tenant')->tenant(User::class)->plugin(LicenseKitPlugin::make());
    expect($tenant->getResources())->toBe([]);
    expect(fn () => $tenant->getPlugin('licensekit')->boot($tenant))->toThrow(LicensingException::class, 'licensing unavailable');
    expect(EntitlementResource::getNavigationLabel())->toBe('Licenses')
        ->and(LicensePlanResource::getNavigationLabel())->toBe('Plans')
        ->and(LicenseKeyResource::getNavigationLabel())->toBe('Keys')
        ->and(ApiClientResource::getNavigationLabel())->toBe('API clients')
        ->and(AuditEventResource::getNavigationLabel())->toBe('Change log');
});

test('unauthenticated requests use host panel login', function () {
    $this->get('/admin/licensekit/products')->assertRedirect('/admin/login');
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
