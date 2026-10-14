<?php

use LicenseKit\Core\Models\DeviceBinding;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Filament\Resources\Entitlements\Pages\ManageEntitlements;
use LicenseKit\Tests\Filament\Fixtures\User;
use Livewire\Livewire;

test('hardware ban administration belongs to host and package entitlement actions stay available', function () {
    adminUser();
    $entitlement = Entitlement::factory()->create(['user_id' => User::factory()->create()->id]);
    DeviceBinding::factory()->create(['user_id' => $entitlement->user_id, 'scope_key' => 'product:'.$entitlement->product_id]);

    $this->get('/admin/licensekit/hwid-bans')->assertNotFound();
    Livewire::test(ManageEntitlements::class)->assertTableActionDoesNotExist('ban_device')
        ->assertTableActionExists('reset_hwid')->assertTableActionExists('inspect_device');
});
