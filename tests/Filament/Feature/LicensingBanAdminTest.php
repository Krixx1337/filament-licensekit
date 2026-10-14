<?php

use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Actions\ManageLicensingBans;
use LicenseKit\Core\Models\AuditEvent;
use LicenseKit\Core\Models\DeviceResetHistory;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\LicensingBan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\Resources\DeviceResetHistory\Pages\ManageDeviceResetHistory;
use LicenseKit\Filament\Resources\Entitlements\Pages\ManageEntitlements;
use LicenseKit\Filament\Resources\LicenseKeys\Pages\ManageLicenseKeys;
use LicenseKit\Filament\Resources\LicensingBans\LicensingBanResource;
use LicenseKit\Filament\Resources\LicensingBans\Pages\ManageLicensingBans as ManageBansPage;
use LicenseKit\LicenseKitPlugin;
use LicenseKit\Tests\Filament\Fixtures\UlidAdminUser;
use LicenseKit\Tests\Filament\Fixtures\User;
use LicenseKit\Tests\Filament\Fixtures\UuidAdminUser;
use Livewire\Livewire;

test('bans page creates permanent and temporary product bans with audited lifting', function () {
    $this->freezeTime();
    $admin = adminUser();
    $customer = User::factory()->create();
    $product = Product::factory()->create();
    $otherProduct = Product::factory()->create();
    $this->get('/admin/licensekit/bans')->assertOk();
    Livewire::test(ManageBansPage::class)->callAction('create', data: ['user_id' => $customer->id, 'product_id' => $otherProduct->id, 'reason' => ' Abuse '])->assertHasNoActionErrors();
    Livewire::test(ManageBansPage::class)->callAction('create', data: ['user_id' => $customer->id, 'product_id' => $product->id,
        'reason' => 'Temporary', 'expires_at' => now()->addHour()->format('Y-m-d H:i:s')])->assertHasNoActionErrors();
    $bans = LicensingBan::orderBy('id')->get();
    expect($bans[0]->product_id)->toBe($otherProduct->id)->and($bans[0]->reason)->toBe('Abuse');
    expect($bans[1]->expires_at->toIso8601String())->toBe(now()->addHour()->toIso8601String());
    Livewire::test(ManageBansPage::class)->callTableAction('lift', $bans[0], data: ['reason' => 'Appeal'])->assertHasNoActionErrors();
    $this->assertDatabaseHas('licensekit_audit_events', ['event' => 'licensing_ban_lifted', 'actor' => 'user:'.$admin->id]);
    expect($bans[0]->fresh()->lift_reason)->toBe('Appeal');
    expect($bans[1]->fresh()->lifted_at)->toBeNull();
});

test('bans cannot be edited deleted replicated or bulk deleted', function () {
    adminUser();
    $ban = LicensingBan::factory()->create(['user_id' => User::factory()->create()->id]);
    expect(LicensingBanResource::canEdit($ban))->toBeFalse()->and(LicensingBanResource::canDelete($ban))->toBeFalse()
        ->and(LicensingBanResource::canReplicate($ban))->toBeFalse()->and(LicensingBanResource::canDeleteAny())->toBeFalse();
    Livewire::test(ManageBansPage::class)->assertTableActionDoesNotExist('edit')->assertTableActionDoesNotExist('delete')->assertTableBulkActionDoesNotExist('delete');
});

test('ban filters separate active expired lifted and product bans', function () {
    $this->freezeTime();
    adminUser();
    $user = User::factory()->create();
    $active = LicensingBan::factory()->create(['user_id' => $user->id]);
    $expired = LicensingBan::factory()->create(['user_id' => $user->id, 'product_id' => Product::factory()->create()->id, 'expires_at' => now()]);
    $lifted = LicensingBan::factory()->create(['user_id' => $user->id, 'lifted_at' => now(), 'lifted_by' => 'test', 'lift_reason' => 'Resolved']);
    Livewire::test(ManageBansPage::class)->filterTable('status', 'active')->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$expired, $lifted]);
    Livewire::test(ManageBansPage::class)->filterTable('status', 'expired')->assertCanSeeTableRecords([$expired])->assertCanNotSeeTableRecords([$active, $lifted]);
    Livewire::test(ManageBansPage::class)->filterTable('status', 'lifted')->assertCanSeeTableRecords([$lifted])->assertCanNotSeeTableRecords([$active, $expired]);
    Livewire::test(ManageBansPage::class)->filterTable('product', $active->product_id)->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$expired, $lifted]);
    Livewire::test(ManageBansPage::class)->filterTable('product', $expired->product_id)->assertCanSeeTableRecords([$expired])->assertCanNotSeeTableRecords([$active, $lifted]);
});

test('ban actions reject permissions removed after mounting', function () {
    $admin = adminUser();
    $user = User::factory()->create();
    $ban = app(ManageLicensingBans::class)->create($user, Product::factory()->create(), 'Abuse', $admin);
    $create = Livewire::test(ManageBansPage::class)->mountAction('create');
    $lift = Livewire::test(ManageBansPage::class)->mountTableAction('lift', $ban);
    Gate::define('licensekit.manage', fn () => false);
    $create->callMountedAction()->assertForbidden();
    $lift->callMountedTableAction()->assertForbidden();
    expect($ban->fresh()->lifted_at)->toBeNull();
    $this->assertDatabaseCount('licensekit_licensing_bans', 1);
    expect(AuditEvent::where('event', 'licensing_ban_lifted')->count())->toBe(0);
});

test('bans page requires panel registration and current host availability', function () {
    $admin = adminUser();
    Filament::setCurrentPanel(Panel::make()->id('outside')->authGuard('staff'));
    Livewire::test(ManageBansPage::class)->assertForbidden();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin->update(['is_suspended' => true]);
    Livewire::test(ManageBansPage::class)->assertForbidden();
});

test('ban reasons and identities render escaped and are searchable', function () {
    adminUser();
    $reason = '<script>alert("ban")</script>';
    $ban = LicensingBan::factory()->create(['user_id' => User::factory()->create()->id, 'reason' => $reason, 'created_by' => '<img src=x onerror=alert(1)>']);
    Livewire::test(ManageBansPage::class)->assertSee($reason)->assertDontSee($reason, escape: false)
        ->assertSee($ban->created_by)->assertDontSee($ban->created_by, escape: false)->searchTable('alert')->assertCanSeeTableRecords([$ban]);
});

test('ban forms reject blank reasons and nonfuture expiry and duplicates show sanitized errors', function () {
    $this->freezeTime();
    adminUser();
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Livewire::test(ManageBansPage::class)->callAction('create', data: ['user_id' => $user->id, 'product_id' => $product->id, 'reason' => ' '])->assertHasActionErrors();
    Livewire::test(ManageBansPage::class)->callAction('create', data: ['user_id' => $user->id, 'product_id' => $product->id, 'reason' => 'Abuse', 'expires_at' => now()->format('Y-m-d H:i:s')])->assertHasActionErrors();
    $this->assertDatabaseCount('licensekit_licensing_bans', 0);
    Livewire::test(ManageBansPage::class)->callAction('create', data: ['user_id' => $user->id, 'product_id' => $product->id, 'reason' => 'Abuse'])->assertHasNoActionErrors();
    Livewire::test(ManageBansPage::class)->callAction('create', data: ['user_id' => $user->id, 'product_id' => $product->id, 'reason' => 'Again'])->assertNotified('Ban already active');
    $this->assertDatabaseCount('licensekit_licensing_bans', 1);
});

test('administrative key claim honors ban without consuming key', function () {
    $admin = adminUser();
    $customer = User::factory()->create();
    $plan = LicensePlan::factory()->create();
    app(ManageLicensingBans::class)->create($customer, $plan->product, 'Abuse', $admin);
    $key = app(CreateBatch::class)->create($plan, 1, 'sale', 'test')->keys->first();
    Livewire::test(ManageLicenseKeys::class)->callTableAction('claim', $key, data: ['user_id' => $customer->id])->assertNotified('License banned');
    expect($key->fresh()->claimed_at)->toBeNull();
    $this->assertDatabaseCount('licensekit_entitlements', 0);
});

test('ban forms support configured UUID and ULID user selection and lifting', function (string $model) {
    config(['auth.providers.users.model' => $model]);
    $this->app['db']->connection()->rollBack();
    $this->artisan('migrate:fresh')->assertSuccessful();
    $this->app['db']->connection()->beginTransaction();
    $admin = $model::create(User::factory()->make()->getAttributes());
    $customer = $model::create(User::factory()->make()->getAttributes());
    $this->actingAs($admin, 'staff');
    Gate::define('licensekit.manage', fn ($actor): bool => $actor->getKey() === $admin->getKey());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $selector = LicensingActions::userSelect();
    expect($selector->getSearchResults($customer->email))->toBe([$customer->getKey() => $customer->email]);
    Livewire::test(ManageBansPage::class)->callAction('create', data: ['user_id' => $customer->getKey(), 'product_id' => Product::factory()->create()->id, 'reason' => 'Abuse'])->assertHasNoActionErrors();
    $ban = LicensingBan::firstOrFail();
    expect($ban->user_id)->toBe($customer->getKey());
    Livewire::test(ManageBansPage::class)->callTableAction('lift', $ban, data: ['reason' => 'Appeal'])->assertHasNoActionErrors();
    $this->assertDatabaseHas('licensekit_audit_events', ['event' => 'licensing_ban_lifted', 'actor' => 'user:'.$admin->getKey()]);
})->with([UuidAdminUser::class, UlidAdminUser::class]);

test('disabled bans are not registered and mounted actions refuse execution', function () {
    $admin = adminUser();
    $user = User::factory()->create();
    $ban = app(ManageLicensingBans::class)->create($user, Product::factory()->create(), 'Abuse', $admin);
    $create = Livewire::test(ManageBansPage::class)->mountAction('create');
    $lift = Livewire::test(ManageBansPage::class)->mountTableAction('lift', $ban);
    config(['licensekit.features.licensing_bans' => false]);
    $panel = Panel::make()->id('disabled')->authGuard('staff');
    LicenseKitPlugin::make()->register($panel);

    expect($panel->getResources())->not->toContain(LicensingBanResource::class);
    $create->callMountedAction()->assertForbidden();
    $lift->callMountedTableAction()->assertForbidden();
    $this->get('/admin/licensekit/bans')->assertForbidden();
    expect($ban->fresh()->lifted_at)->toBeNull();
    $this->assertDatabaseCount('licensekit_licensing_bans', 1);
});

test('ban creation requires product selection', function () {
    adminUser();
    $user = User::factory()->create();

    Livewire::test(ManageBansPage::class)->callAction('create', data: ['user_id' => $user->id, 'reason' => 'Abuse'])
        ->assertHasActionErrors(['product_id' => 'required']);

    $this->assertDatabaseCount('licensekit_licensing_bans', 0);
});

test('configured usernames display and search across licensing administration', function () {
    Schema::table('users', fn ($table) => $table->string('username')->nullable());
    config(['licensekit.user.display_column' => 'username']);
    adminUser();
    $owner = User::factory()->create(['username' => 'player-owner']);
    $actor = User::factory()->create(['username' => 'player-actor']);
    $ban = LicensingBan::factory()->create(['user_id' => $owner->id]);
    $entitlement = Entitlement::factory()->create(['user_id' => $owner->id]);
    $history = DeviceResetHistory::factory()->create(['user_id' => $owner->id, 'actor_id' => $actor->id]);

    Livewire::test(ManageBansPage::class)->assertSee('player-owner')->searchTable('player-owner')->assertCanSeeTableRecords([$ban]);
    Livewire::test(ManageEntitlements::class)
        ->assertSee('player-owner')->searchTable('player-owner')->assertCanSeeTableRecords([$entitlement]);
    Livewire::test(ManageDeviceResetHistory::class)->filterTable('user_id', (string) $owner->id)
        ->assertSee('player-owner')->assertSee('player-actor')->assertCanSeeTableRecords([$history]);
});

test('configured user selector limits results and resolves selected users outside initial options', function () {
    Schema::table('users', fn ($table) => $table->string('username')->nullable());
    config(['licensekit.user.display_column' => 'username']);
    adminUser()->update(['username' => 'staff']);
    User::factory()->count(51)->create(['username' => 'matching-player']);
    $last = User::factory()->create(['username' => 'selected-player']);
    $component = Livewire::test(ManageBansPage::class)->mountAction('create')->fillForm(['user_id' => $last->getKey()]);
    $select = $component->instance()->getSchema('mountedActionSchema0')->getComponentByStatePath('user_id');

    expect($select->getOptions())->toHaveCount(50)->not->toHaveKey($last->getKey());
    expect($select->getSearchResults('matching'))->toHaveCount(50);
    expect($select->getSearchResults('selected'))->toBe([$last->getKey() => 'selected-player']);
    expect($select->getOptionLabel())->toBe('selected-player');
});
