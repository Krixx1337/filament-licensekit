<?php

use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Filament\Pages\ConnectYourApp;
use LicenseKit\Filament\Resources\LicenseKeys\Pages\ManageLicenseKeys;
use LicenseKit\Filament\Resources\Licenses\Pages\ManageLicenses;
use LicenseKit\Tests\Filament\Fixtures\User;
use Livewire\Livewire;

test('generate keys shows result and requires explicit download', function () {
    adminUser();
    $plan = LicensePlan::factory()->create();
    Livewire::test(ManageLicenseKeys::class)->callAction('create_keys', data: [
        'product_id' => $plan->product_id, 'plan_id' => $plan->id, 'quantity' => 2, 'label' => 'Giveaway',
    ])->assertHasNoErrors()->assertNoFileDownloaded()->assertSet('mountedActions.0.name', 'result');
    $this->assertDatabaseCount('licensekit_keys', 2);
});

test('give license issues and redeems in one transaction', function () {
    adminUser();
    $user = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    Livewire::test(ManageLicenses::class)->callAction('give_license', data: [
        'user_id' => $user->id, 'product_id' => $plan->product_id, 'custom_days' => 3,
    ])->assertHasNoActionErrors();
    expect(License::sole()->user_id)->toBe($user->id);
    $this->assertDatabaseCount('licensekit_batches', 1);
    expect(LicenseKey::sole()->redeemed_at)->not->toBeNull();
});

test('connect diagnostic shows access without locking device or changing clock', function () {
    adminUser();
    $user = User::factory()->create();
    $license = License::factory()->create(['user_id' => $user->id]);
    $before = $license->fresh()->getAttributes();
    Livewire::test(ConnectYourApp::class)->callAction('check_customer', data: ['user_id' => $user->id, 'product_id' => $license->product_id, 'device' => 'pc'])
        ->assertHasNoActionErrors()->assertSee('Allowed until');
    expect($license->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('licensekit_loader_sessions', 0);
});

test('new admin pages and license detail render', function () {
    adminUser();
    $license = License::factory()->create(['user_id' => User::factory()->create()->id]);
    foreach (['dashboard', 'connect-your-app', 'team-access', 'device-bans', 'licenses/'.$license->id] as $path) {
        $this->get('/admin/licensekit/'.$path)->assertOk();
    }
});
