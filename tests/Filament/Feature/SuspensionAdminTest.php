<?php

use Illuminate\Support\Facades\Schema;
use LicenseKit\Core\Actions\Keys;
use LicenseKit\Core\Data\IssueContext;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\Resources\LicenseKeys\Pages\ManageLicenseKeys;
use LicenseKit\Filament\Resources\Licenses\Pages\ManageLicenses;
use LicenseKit\Tests\Filament\Fixtures\User;
use Livewire\Livewire;

test('suspend a customer creates a suspended license even before a purchase', function () {
    adminUser();
    $customer = User::factory()->create();
    $product = Product::factory()->create();

    Livewire::test(ManageLicenses::class)->callAction('suspend_customer', data: [
        'user_id' => $customer->id, 'product_id' => $product->id, 'reason' => 'Chargeback',
    ])->assertHasNoActionErrors();

    $license = License::where('user_id', $customer->id)->sole();
    expect($license->status)->toBe(License::SUSPENDED)->and($license->product_id)->toBe($product->id);
    Livewire::test(ManageLicenses::class)->assertSee('Suspended')->assertTableActionVisible('unsuspend', $license)->assertTableActionHidden('suspend', $license);
});

test('license row actions suspend, unsuspend, cancel and restore with optional notes', function () {
    adminUser();
    $license = License::factory()->create(['user_id' => User::factory()->create()->id]);
    $page = fn () => Livewire::test(ManageLicenses::class);

    $page()->callTableAction('suspend', $license, data: ['until' => now()->addDay()->setTimezone(config('app.timezone'))->format('Y-m-d H:i')])->assertHasNoTableActionErrors();
    expect($license->fresh()->isSuspended())->toBeTrue()->and($license->fresh()->suspended_until)->not->toBeNull();
    $page()->callTableAction('unsuspend', $license)->assertHasNoTableActionErrors();
    expect($license->fresh()->status)->toBe(License::ACTIVE);
    $page()->callTableAction('cancel', $license, data: ['reason' => 'Refund'])->assertHasNoTableActionErrors();
    expect($license->fresh()->status)->toBe(License::REVOKED);
    $page()->assertSee('Cancelled')->callTableAction('restore', $license)->assertHasNoTableActionErrors();
    expect($license->fresh()->status)->toBe(License::ACTIVE);
});

test('administrative key claim honors suspension without consuming the key', function () {
    adminUser();
    $customer = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    $key = app(Keys::class)->issue($plan, 1, new IssueContext(source: 'app', reference: 'sale'))->keys->first();
    License::factory()->create(['user_id' => $customer->id, 'product_id' => $plan->product_id, 'status' => License::SUSPENDED]);

    Livewire::test(ManageLicenseKeys::class)->callTableAction('claim', $key, data: ['user_id' => $customer->id])->assertNotified('Your license is suspended. Contact support for help.');

    expect($key->fresh()->redeemed_at)->toBeNull();
});

test('configured usernames display and search across licensing administration', function () {
    Schema::table('users', fn ($table) => $table->string('username')->nullable());
    config(['licensekit.user_label' => 'username']);
    adminUser();
    $owner = User::factory()->create(['username' => 'player-owner']);
    $license = License::factory()->create(['user_id' => $owner->id]);

    Livewire::test(ManageLicenses::class)
        ->assertSee('player-owner')->searchTable('player-owner')->assertCanSeeTableRecords([$license]);
});

test('configured user selector limits results and resolves selected users outside initial options', function () {
    Schema::table('users', fn ($table) => $table->string('username')->nullable());
    config(['licensekit.user_label' => 'username']);
    adminUser()->update(['username' => 'staff']);
    User::factory()->count(51)->create(['username' => 'matching-player']);
    $last = User::factory()->create(['username' => 'selected-player']);
    $component = Livewire::test(ManageLicenses::class)->mountAction('suspend_customer')->fillForm(['user_id' => $last->getKey()]);
    $select = $component->instance()->getSchema('mountedActionSchema0')->getComponentByStatePath('user_id');

    expect($select->getOptions())->toHaveCount(50)->not->toHaveKey($last->getKey());
    expect($select->getSearchResults('matching'))->toHaveCount(50);
    expect($select->getSearchResults('selected'))->toBe([$last->getKey() => 'selected-player']);
    expect($select->getOptionLabel())->toBe('selected-player');
});
