<?php

use LicenseKit\Core\Actions\Keys;
use LicenseKit\Core\Data\IssueContext;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Filament\Resources\Batches\Pages\ManageBatches;
use LicenseKit\Filament\Resources\LicenseKeys\Pages\ManageLicenseKeys;
use LicenseKit\Filament\Resources\Products\Pages\ListProducts;
use Livewire\Livewire;

test('key tabs count and filter unused, redeemed and cancelled keys', function () {
    adminUser();
    $unused = LicenseKey::factory()->create();
    $redeemed = LicenseKey::factory()->create(['redeemed_at' => now()]);
    $cancelled = LicenseKey::factory()->create(['cancelled_at' => now()]);

    $page = Livewire::test(ManageLicenseKeys::class);
    expect(collect($page->instance()->getTabs())->map(fn ($tab) => $tab->getBadge())->all())
        ->toBe(['all' => '3', 'unused' => '1', 'redeemed' => '1', 'cancelled' => '1']);
    $page->set('activeTab', 'unused')->assertCanSeeTableRecords([$unused])->assertCanNotSeeTableRecords([$redeemed, $cancelled]);
});

test('the generate keys wizard creates the keys and shows them once', function () {
    adminUser();
    $plan = LicensePlan::factory()->create(['name' => 'Monthly', 'duration_days' => 30]);

    Livewire::test(ManageLicenseKeys::class)->mountAction('create_keys')
        ->fillForm(['product_id' => $plan->product_id, 'plan_id' => $plan->id, 'quantity' => 3])
        ->callMountedAction()->assertHasNoActionErrors()->assertSet('mountedActions.0.name', 'result');

    $this->assertDatabaseCount('licensekit_keys', 3);
});

test('generate keys from a product row starts with that product selected', function () {
    adminUser();
    $plan = LicensePlan::factory()->create();

    Livewire::test(ListProducts::class)->mountTableAction('create_keys', $plan->product)
        ->assertSchemaStateSet(['product_id' => $plan->product_id], 'mountedActionSchema0');
});

test('selected keys download as a text file', function () {
    adminUser();
    $keys = LicenseKey::factory()->count(2)->create();

    Livewire::test(ManageLicenseKeys::class)->callTableBulkAction('download_selected', $keys)->assertFileDownloaded('license-keys.txt');
});

test('batches show product, progress and an empty state', function () {
    $actor = adminUser();
    Livewire::test(ManageBatches::class)->assertSee('No key batches yet');

    $plan = LicensePlan::factory()->create(['name' => 'Weekly']);
    $batch = app(Keys::class)->issue($plan, 4, new IssueContext(source: 'admin', actor: $actor, label: 'Stream giveaway'));
    $batch->keys[0]->update(['redeemed_at' => now()]);

    Livewire::test(ManageBatches::class)->assertSee(['Stream giveaway', $plan->product->name, 'Weekly', '1 of 4', '3 unused', 'Admin panel']);
});
