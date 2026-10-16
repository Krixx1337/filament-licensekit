<?php

use Illuminate\Validation\ValidationException;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\Keys;
use LicenseKit\Core\Actions\ManageApiConnections as Connections;
use LicenseKit\Core\Data\IssueContext;
use LicenseKit\Core\Models\ApiConnection;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Filament\Resources\ApiConnections\Pages\ManageApiConnections;
use LicenseKit\Filament\Resources\Batches\Pages\ManageBatches;
use LicenseKit\Filament\Resources\LicenseKeys\Pages\ManageLicenseKeys;
use LicenseKit\Filament\Resources\Licenses\Pages\ViewLicense;
use LicenseKit\Tests\Filament\Fixtures\User;
use Livewire\Livewire;

test('generate keys wizard saves chosen reseller', function () {
    adminUser();
    $reseller = User::factory()->create();
    $plan = LicensePlan::factory()->create();

    Livewire::test(ManageLicenseKeys::class)->mountAction('create_keys')
        ->fillForm(['product_id' => $plan->product_id, 'plan_id' => $plan->id, 'quantity' => 2, 'reseller_id' => $reseller->id])
        ->callMountedAction()->assertHasNoActionErrors();

    $this->assertDatabaseHas('licensekit_batches', ['reseller_id' => $reseller->id, 'quantity' => 2, 'api_connection_id' => null]);
});

test('batch reseller column and filter show matching purchases', function () {
    adminUser();
    $reseller = User::factory()->create();
    $own = Batch::factory()->create(['reseller_id' => $reseller->id]);
    $shop = Batch::factory()->create();
    $other = Batch::factory()->create(['reseller_id' => User::factory()->create()->id]);

    Livewire::test(ManageBatches::class)->assertSee([$reseller->name, 'Your shop'])
        ->filterTable('reseller_id', $reseller->id)->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$shop, $other]);
});

test('connection form saves and displays optional owner', function () {
    adminUser();
    $reseller = User::factory()->create();

    Livewire::test(ManageApiConnections::class)->callAction('create', data: [
        'name' => 'Reseller bot', 'user_id' => $reseller->id, 'is_active' => true, 'all_products' => true, 'max_per_request' => 25,
    ])->assertHasNoErrors();

    expect(ApiConnection::sole()->user_id)->toBe($reseller->id);
    Livewire::test(ManageApiConnections::class)->assertSee($reseller->name);
});

test('connection owner must exist', function () {
    $admin = adminUser();

    expect(fn () => app(Connections::class)->save(null, [
        'name' => 'Bot', 'user_id' => 999999, 'is_active' => true, 'max_per_request' => 25,
    ], $admin))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('licensekit_api_connections', 0);
});

test('keys and license page identify reseller who sold redeemed key', function () {
    $admin = adminUser();
    $reseller = User::factory()->create();
    $customer = User::factory()->create();
    $batch = app(Keys::class)->issue(LicensePlan::factory()->create(), 1, new IssueContext(reseller: $reseller));
    $license = app(ClaimLicense::class)->claimFor($admin, $customer, $batch->keys[0]);

    Livewire::test(ManageLicenseKeys::class)->assertSee('Sold by '.$reseller->name);
    Livewire::test(ViewLicense::class, ['record' => $license->id])->assertSee(['Sold by', $reseller->name]);
});
