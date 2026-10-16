<?php

use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Models\DeviceResetHistory;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\Resources\DeviceResetHistory\DeviceResetHistoryResource;
use LicenseKit\Filament\Resources\DeviceResetHistory\Pages\ManageDeviceResetHistory;
use LicenseKit\Tests\Filament\Fixtures\User;
use Livewire\Livewire;

test('reset history is always registered and read only for licensed administrators', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'staff')->get('/admin/licensekit/device-reset-history')->assertForbidden();
    adminUser();
    $history = DeviceResetHistory::factory()->create();
    $this->get('/admin/licensekit/device-reset-history')->assertOk();
    expect(DeviceResetHistoryResource::canCreate())->toBeFalse()->and(DeviceResetHistoryResource::canEdit($history))->toBeFalse()
        ->and(DeviceResetHistoryResource::canDelete($history))->toBeFalse()->and(DeviceResetHistoryResource::canDeleteAny())->toBeFalse();
    Livewire::test(ManageDeviceResetHistory::class)->assertCanSeeTableRecords([$history])
        ->filterTable('user_id', (string) $history->user_id)->assertCanSeeTableRecords([$history])
        ->assertActionDoesNotExist('create')->assertTableActionDoesNotExist('edit')->assertTableActionDoesNotExist('delete')
        ->assertTableActionDoesNotExist('reset_hwid')->assertTableBulkActionDoesNotExist('delete');
});

test('reset history requires registered panel and rechecks permission on subsequent requests', function () {
    adminUser();
    $component = Livewire::test(ManageDeviceResetHistory::class);
    Gate::define('licensekit.manage', fn () => false);
    $component->searchTable('owner')->assertForbidden();
    Gate::define('licensekit.manage', fn () => true);
    Filament::setCurrentPanel(Panel::make()->id('outside')->authGuard('staff'));
    Livewire::test(ManageDeviceResetHistory::class)->assertForbidden();
});

test('user filter limits reset history', function () {
    adminUser();
    $owner = User::factory()->create(['email' => 'history-owner@example.com']);
    $actor = User::factory()->create(['email' => 'history-actor@example.com']);
    $other = User::factory()->create(['email' => 'other-owner@example.com']);
    $product = Product::factory()->create(['name' => 'Inspected game']);
    $global = DeviceResetHistory::factory()->create(['user_id' => $owner->id, 'scope' => 'global', 'product_id' => null, 'performed_by' => 'customer', 'before_hwid' => ['value' => 'cleared-laptop']]);
    $target = DeviceResetHistory::factory()->create(['user_id' => $owner->id, 'actor_id' => $actor->id, 'scope' => 'per_product', 'product_id' => $product->id, 'performed_by' => 'admin', 'before_hwid' => ['value' => '<script>alert("inspect")</script>']]);
    $otherHistory = DeviceResetHistory::factory()->create(['user_id' => $other->id, 'before_hwid' => ['value' => 'other-pc']]);

    Livewire::test(ManageDeviceResetHistory::class)->assertSee('cleared-laptop')->assertSee('alert("inspect")')->assertSee('other-pc')
        ->assertDontSee('<script>alert("inspect")</script>', escape: false)
        ->assertDontSee('{"value":"cleared-laptop"}', escape: false)
        ->assertCanSeeTableRecords([$global, $target, $otherHistory]);
    $page = Livewire::test(ManageDeviceResetHistory::class)->filterTable('user_id', (string) $owner->id);
    $page->assertSee('Inspected game')->assertSee('All products')->assertSee('cleared-laptop')
        ->assertCanSeeTableRecords([$global, $target])->assertCanNotSeeTableRecords([$otherHistory]);
    $page->filterTable('scope', 'global')->assertCanSeeTableRecords([$global])->assertCanNotSeeTableRecords([$target, $otherHistory]);
    Livewire::test(ManageDeviceResetHistory::class)->filterTable('user_id', (string) $owner->id)->filterTable('product', $product->id)
        ->assertCanSeeTableRecords([$target])->assertCanNotSeeTableRecords([$global, $otherHistory]);
    Livewire::test(ManageDeviceResetHistory::class)->filterTable('user_id', (string) $owner->id)->filterTable('performed_by', 'admin')
        ->assertCanSeeTableRecords([$target])->assertCanNotSeeTableRecords([$global, $otherHistory]);
    Livewire::test(ManageDeviceResetHistory::class)->filterTable('user_id', (string) $owner->id)->searchTable('history-actor@example.com')
        ->assertCanSeeTableRecords([$target])->assertCanNotSeeTableRecords([$global, $otherHistory]);
    Livewire::test(ManageDeviceResetHistory::class)->filterTable('user_id', (string) $owner->id)->searchTable('other-owner@example.com')
        ->assertCanNotSeeTableRecords([$otherHistory, $target, $global]);

    $orphan = DeviceResetHistory::factory()->create(['user_id' => 'owner-unavailable', 'actor_id' => 'actor-unavailable', 'before_hwid' => ['value' => 'orphan-pc']]);
    $page = Livewire::test(ManageDeviceResetHistory::class);
    $select = $page->instance()->getTableFiltersForm()->getComponentByStatePath('user_id.value');
    expect($select->getSearchResults('owner-unavailable'))->toBe(['owner-unavailable' => 'owner-unavailable']);
    $page->filterTable('user_id', 'owner-unavailable')->searchTable('actor-unavailable')
        ->assertSee('orphan-pc')->assertCanSeeTableRecords([$orphan])->assertCanNotSeeTableRecords([$target, $global, $otherHistory]);
});

test('deleted references retain visible IDs and reset reasons render escaped with full viewing', function () {
    adminUser();
    $reason = '<script>alert("history")</script>'.str_repeat(' Full support note.', 10);
    $history = DeviceResetHistory::factory()->create(['user_id' => 'deleted-owner', 'actor_id' => 'deleted-actor', 'scope' => 'per_product', 'product_id' => 999, 'reason' => $reason]);
    $page = Livewire::test(ManageDeviceResetHistory::class);
    expect($page->instance()->getTableFiltersForm()->getComponentByStatePath('user_id.value')->getSearchResults('deleted-owner'))->toBe(['deleted-owner' => 'deleted-owner']);
    $page->filterTable('user_id', 'deleted-owner')->assertSee('deleted-owner')->assertSee('deleted-actor')->assertSee('999')
        ->assertDontSee('<script>alert("history")</script>', escape: false)
        ->mountTableAction('details', $history)->assertTableActionDataSet(['reason' => $reason]);
});

test('reset details show the cleared device', function () {
    adminUser();
    $before = ['v' => 1, 'ids' => ['board' => 'old']];
    $history = DeviceResetHistory::factory()->create(['before_hwid' => $before]);
    $missing = DeviceResetHistory::factory()->create(['user_id' => $history->user_id, 'before_hwid' => null]);

    Livewire::test(ManageDeviceResetHistory::class)->assertSee(json_encode($before, JSON_THROW_ON_ERROR))->assertSee('Unavailable')
        ->filterTable('user_id', (string) $history->user_id)
        ->mountTableAction('details', $history)->assertTableActionDataSet([
            'before_hwid' => json_encode($before, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        ]);
    Livewire::test(ManageDeviceResetHistory::class)->filterTable('user_id', (string) $history->user_id)
        ->mountTableAction('details', $missing)->assertTableActionDataSet(['before_hwid' => 'Unavailable']);
});
