<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\Pages\Dashboard;
use LicenseKit\Filament\Resources\Batches\Pages\ManageBatches;
use LicenseKit\Filament\Resources\LicenseKeys\Pages\ManageLicenseKeys;
use LicenseKit\Filament\Resources\Licenses\Pages\ManageLicenses;
use LicenseKit\Filament\Resources\Products\Pages\ListProducts;
use LicenseKit\Tests\Filament\Fixtures\User;
use Livewire\Livewire;

/** An admin limited to one product, the way a host gives a partner their own product. */
function productAdmin(Product $product): User
{
    $user = User::factory()->create();
    Gate::define('licensekit.product', fn ($actor, Product $candidate): bool => $candidate->is($product));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    test()->actingAs($user, 'staff');

    return $user;
}

beforeEach(function () {
    $this->own = LicensePlan::factory()->create();
    $this->other = LicensePlan::factory()->create();
});

test('limited staff only see their product in every list', function () {
    productAdmin($this->own->product);
    $customer = fn () => User::factory()->create()->id;
    $ownLicense = License::factory()->create(['product_id' => $this->own->product_id, 'user_id' => $customer(), 'expires_at' => now()->addDays(30)]);
    $otherLicense = License::factory()->create(['product_id' => $this->other->product_id, 'user_id' => $customer(), 'expires_at' => now()->addDays(30)]);
    $ownKey = LicenseKey::factory()->for(Batch::factory()->for($this->own, 'plan'))->create();
    $otherKey = LicenseKey::factory()->for(Batch::factory()->for($this->other, 'plan'))->create();

    $licenses = Livewire::test(ManageLicenses::class)->assertCanSeeTableRecords([$ownLicense])->assertCanNotSeeTableRecords([$otherLicense]);
    expect($licenses->instance()->getTabs()['all']->getBadge())->toBe('1');
    Livewire::test(ManageLicenseKeys::class)->assertCanSeeTableRecords([$ownKey])->assertCanNotSeeTableRecords([$otherKey]);
    Livewire::test(ManageBatches::class)->assertCanSeeTableRecords([$ownKey->batch])->assertCanNotSeeTableRecords([$otherKey->batch]);
    Livewire::test(ListProducts::class)->assertCanSeeTableRecords([$this->own->product])->assertCanNotSeeTableRecords([$this->other->product]);
    $this->get('/admin/licensekit/licenses/'.$otherLicense->getRouteKey())->assertNotFound();
});

test('limited staff manage their product but no other', function () {
    productAdmin($this->own->product);
    $customer = User::factory()->create();

    Livewire::test(ManageLicenseKeys::class)->mountAction('create_keys')
        ->assertFormFieldExists('product_id', 'mountedActionSchema0', fn ($field): bool => array_keys($field->getOptions()) === [$this->own->product_id])
        ->fillForm(['product_id' => $this->own->product_id, 'plan_id' => $this->own->id, 'quantity' => 2])
        ->callMountedAction()->assertHasNoActionErrors();
    expect(LicenseKey::count())->toBe(2);

    Livewire::test(ManageLicenseKeys::class)->mountAction('create_keys')
        ->fillForm(['product_id' => $this->other->product_id, 'plan_id' => $this->other->id, 'quantity' => 1])
        ->callMountedAction();
    Livewire::test(ManageLicenses::class)->callAction('give_license', ['user_id' => $customer->id, 'product_id' => $this->other->product_id, 'plan_id' => $this->other->id]);

    expect(LicenseKey::count())->toBe(2)->and(License::where('product_id', $this->other->product_id)->exists())->toBeFalse();
});

test('limited staff never see screens that cover every product', function () {
    productAdmin($this->own->product);

    foreach (['connections', 'device-bans', 'activity-log', 'team-access', 'connect-your-app', 'products/create'] as $slug) {
        $this->get('/admin/licensekit/'.$slug)->assertForbidden();
    }
    $this->get('/admin/licensekit/dashboard')->assertOk();
    $dashboard = Livewire::test(Dashboard::class);
    expect(array_keys($dashboard->instance()->getFiltersForm()->getComponent('product_id')->getOptions()))->toBe([$this->own->product_id]);
});

test('without the product gate every admin sees every product', function () {
    adminUser();

    Livewire::test(ListProducts::class)->assertCanSeeTableRecords([$this->own->product, $this->other->product]);
    $this->get('/admin/licensekit/connections')->assertOk();
});
