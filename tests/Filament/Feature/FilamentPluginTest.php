<?php

use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Actions\Keys;
use LicenseKit\Core\Data\IssueContext;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Filament\Resources\LicenseKeys\Pages\ManageLicenseKeys;
use LicenseKit\Filament\Resources\Products\Pages\ManageProducts;
use LicenseKit\Tests\Filament\Fixtures\User;
use Livewire\Livewire;

test('core resources require panel registration and host gate', function () {
    $user = User::factory()->create();
    Gate::define('licensekit.admin', fn (): bool => false);
    $this->actingAs($user, 'staff')->get('/admin/licensekit/products')->assertForbidden();
    adminUser();
    $this->get('/admin/licensekit/products')->assertOk();
    foreach (['license-keys', 'license-keys', 'licenses', 'connections', 'activity-log'] as $slug) {
        $this->get('/admin/licensekit/'.$slug)->assertOk();
    }
});

test('product creation validates catalog settings and records panel actor once', function () {
    $admin = adminUser();
    $data = ['plans' => [], 'name' => 'Software', 'slug' => 'software', 'status' => 'active'];
    Livewire::test(ManageProducts::class)->callAction('create', data: $data)->assertHasNoActionErrors();
    $this->assertDatabaseHas('licensekit_products', ['slug' => 'software']);
    $this->assertDatabaseCount('licensekit_activity_log', 1);
    $this->assertDatabaseHas('licensekit_activity_log', ['actor' => 'user:'.$admin->id, 'event' => 'product_created']);
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

test('unauthenticated requests use host panel login', function () {
    $this->get('/admin/licensekit/products')->assertRedirect('/admin/login');
});

test('admin sees generated batch details', function () {
    $actor = adminUser();
    $plan = LicensePlan::factory()->create();
    $batch = app(Keys::class)->issue($plan, 2, new IssueContext(source: 'admin', actor: $actor, reference: 'giveaway', label: 'October giveaway'));
    Livewire::test(ManageLicenseKeys::class)->assertCanSeeTableRecords(Batch::first()->keys)
        ->assertSee('October giveaway');
    $this->assertDatabaseCount('licensekit_keys', 2);
});

test('batch labels render as escaped text in the key table', function () {
    adminUser();
    $plan = LicensePlan::factory()->create();
    $label = '<script>alert("giveaway")</script>';
    $batch = app(Keys::class)->issue($plan, 1, new IssueContext(source: 'app', reference: 'giveaway', label: $label));
    Livewire::test(ManageLicenseKeys::class)->assertCanSeeTableRecords($batch->keys)
        ->assertSee($label)->assertDontSee($label, escape: false);
});
