<?php

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\Resources\LicenseKeys\Pages\ManageLicenseKeys;
use LicenseKit\Filament\Resources\Licenses\Pages\ManageLicenses;
use LicenseKit\Tests\Filament\Fixtures\User;
use Livewire\Livewire;

function supportUser(): User
{
    $user = User::factory()->create();
    Gate::define('licensekit.admin', fn (): bool => false);
    Gate::define('licensekit.support', fn ($actor): bool => $actor->getKey() === $user->getKey());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    test()->actingAs($user, 'staff');

    return $user;
}

class DenyProductBrowsingPolicy
{
    public function viewAny(Model $user): bool
    {
        return false;
    }
}

test('every panel user is a LicenseKit admin unless the host redefines the gate', function () {
    $this->actingAs(User::factory()->create(), 'staff');

    foreach (['dashboard', 'products', 'batches', 'connections', 'activity-log', 'licenses', 'license-keys'] as $slug) {
        $this->get('/admin/licensekit/'.$slug)->assertOk();
    }
});

test('support staff browse licenses keys and the activity log but not admin pages', function () {
    supportUser();

    foreach (['licenses', 'license-keys', 'activity-log'] as $slug) {
        $this->get('/admin/licensekit/'.$slug)->assertOk();
    }
    foreach (['products', 'batches', 'connections', 'device-bans'] as $slug) {
        $this->get('/admin/licensekit/'.$slug)->assertForbidden();
    }
});

test('support staff fix licenses but cannot cancel them or hand out keys', function () {
    supportUser();
    $license = License::factory()->create(['user_id' => User::factory()->create()->id, 'device' => 'pc']);
    $key = LicenseKey::factory()->create();

    Livewire::test(ManageLicenses::class)
        ->assertTableActionVisible('suspend', $license)->assertTableActionVisible('extend', $license)
        ->assertTableActionVisible('reset_hwid', $license)->assertActionVisible('suspend_customer')
        ->assertTableActionHidden('cancel', $license);
    Livewire::test(ManageLicenseKeys::class)
        ->assertTableActionHidden('claim', $key)->assertTableActionHidden('revoke_key', $key)->assertTableActionHidden('edit_note', $key);

    Livewire::test(ManageLicenses::class)->callTableAction('suspend', $license)->assertHasNoTableActionErrors();
    expect($license->fresh()->isSuspended())->toBeTrue();
});

test('losing a role after the page loads blocks the action', function () {
    supportUser();
    $license = License::factory()->create(['user_id' => User::factory()->create()->id]);
    $component = Livewire::test(ManageLicenses::class)->mountTableAction('suspend', $license);

    Gate::define('licensekit.support', fn (): bool => false);

    $component->callMountedTableAction()->assertForbidden();
    expect($license->fresh()->status)->toBe(License::ACTIVE);
});

test('host model policies still apply on top of the LicenseKit role', function () {
    adminUser();
    Gate::policy(Product::class, DenyProductBrowsingPolicy::class);

    $this->get('/admin/licensekit/products')->assertForbidden();
    $this->get('/admin/licensekit/license-keys')->assertOk();
});

class DenyLicenseUpdatePolicy
{
    public function viewAny(Model $user): bool
    {
        return true;
    }

    public function view(Model $user, License $license): bool
    {
        return true;
    }

    public function update(Model $user, License $license): bool
    {
        return false;
    }
}

test('native license update policy also restricts custom actions', function () {
    supportUser();
    Gate::policy(License::class, DenyLicenseUpdatePolicy::class);
    $license = License::factory()->create(['user_id' => User::factory()->create()->id]);
    Livewire::test(ManageLicenses::class)->assertTableActionHidden('suspend', $license);
    expect($license->fresh()->status)->toBe('active');
});
