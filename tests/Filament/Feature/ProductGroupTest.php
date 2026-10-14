<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Models\AuditEvent;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Core\Models\ProductGroup;
use LicenseKit\Filament\CatalogWrites;
use LicenseKit\Filament\Resources\LicensePlans\Pages\ManageLicensePlans;
use LicenseKit\Filament\Resources\ProductGroups\Pages\ManageProductGroups;
use LicenseKit\Filament\Resources\Products\Pages\ManageProducts;
use LicenseKit\Tests\Core\Fixtures\ClientSessions;
use LicenseKit\Tests\Filament\Fixtures\User;
use Livewire\Livewire;

test('group management uses host panel authentication and admin access', function () {
    $this->get('/admin/licensekit/product-groups')->assertRedirect('/admin/login');
    $this->actingAs(User::factory()->create(), 'staff')->get('/admin/licensekit/product-groups')->assertForbidden();
    adminUser();
    $this->get('/admin/licensekit/product-groups')->assertOk();
});

test('setup diagnostics reports a missing product group table', function () {
    adminUser();
    Schema::drop('licensekit_product_groups');
    $this->artisan('licensekit:check')->expectsOutputToContain('Missing table: licensekit_product_groups.')
        ->assertFailed();
});

test('admin creates and renames product groups with audit history', function () {
    $admin = adminUser();
    Livewire::test(ManageProductGroups::class)->callAction('create', data: ['name' => ' GTA V '])->assertHasNoActionErrors();
    $group = ProductGroup::first();
    expect($group->name)->toBe('GTA V');
    Livewire::test(ManageProductGroups::class)->callTableAction('edit', $group, data: ['name' => 'CS2'])->assertHasNoActionErrors();
    expect($group->fresh()->name)->toBe('CS2');
    $this->assertDatabaseHas('licensekit_audit_events', ['event' => 'configuration_created', 'subject_type' => ProductGroup::class, 'actor' => 'user:'.$admin->id]);
    $this->assertDatabaseHas('licensekit_audit_events', ['event' => 'configuration_updated', 'subject_type' => ProductGroup::class]);
    Livewire::test(ManageProductGroups::class)->callAction('create', data: ['name' => 'CS2'])->assertHasNoActionErrors();
    $this->assertDatabaseCount('licensekit_product_groups', 2);
});

test('group names reject blank and oversized values', function (string $name) {
    adminUser();
    Livewire::test(ManageProductGroups::class)->callAction('create', data: ['name' => $name])->assertHasActionErrors(['name']);
    expect(fn () => ProductGroup::factory()->create(['name' => $name]))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('licensekit_product_groups', 0);
})->with(['', '   ', str_repeat('x', 256)]);

test('products can be assigned moved and cleared while invalid groups are rejected', function () {
    adminUser();
    $groups = ProductGroup::factory()->count(2)->create();
    $data = Product::factory()->make()->toArray();
    Livewire::test(ManageProducts::class)->callAction('create', data: [...$data, 'product_group_id' => $groups[0]->id])->assertHasNoActionErrors();
    $product = Product::first();
    expect($product->group->id)->toBe($groups[0]->id);
    Livewire::test(ManageProducts::class)->callTableAction('edit', $product, data: ['product_group_id' => $groups[1]->id])->assertHasNoActionErrors();
    expect($product->fresh()->product_group_id)->toBe($groups[1]->id);
    Livewire::test(ManageProducts::class)->callTableAction('edit', $product, data: ['product_group_id' => 999999])->assertHasActionErrors(['product_group_id']);
    expect($product->fresh()->product_group_id)->toBe($groups[1]->id);
    Livewire::test(ManageProducts::class)->callTableAction('edit', $product, data: ['product_group_id' => null])->assertHasNoActionErrors();
    expect($product->fresh()->product_group_id)->toBeNull();
});

test('products and plans filter grouped and ungrouped records', function () {
    adminUser();
    $group = ProductGroup::factory()->create(['name' => 'GTA V']);
    $grouped = Product::factory()->create(['product_group_id' => $group->id]);
    $other = Product::factory()->create(['product_group_id' => ProductGroup::factory()->create()->id]);
    $ungrouped = Product::factory()->create();
    $plans = collect([$grouped, $other, $ungrouped])->map(fn (Product $product) => LicensePlan::factory()->create(['product_id' => $product->id]));
    app(CreateBatch::class)->create($plans[0], 2, 'catalog', 'test');
    Livewire::test(ManageProducts::class)->filterTable('group', $group->id)->assertCanSeeTableRecords([$grouped])->assertCanNotSeeTableRecords([$other, $ungrouped]);
    Livewire::test(ManageProducts::class)->filterTable('group', '__empty')->assertCanSeeTableRecords([$ungrouped])->assertCanNotSeeTableRecords([$grouped, $other]);
    Livewire::test(ManageLicensePlans::class)->filterTable('group', $group->id)->assertCanSeeTableRecords([$plans[0]])->assertCanNotSeeTableRecords([$plans[1], $plans[2]])->assertTableColumnStateSet('unclaimed_count', 2, $plans[0]);
    Livewire::test(ManageLicensePlans::class)->filterTable('group', '__empty')->assertCanSeeTableRecords([$plans[2]])->assertCanNotSeeTableRecords([$plans[0], $plans[1]]);
});

test('renaming moving and deleting groups preserves products and online licensing state', function () {
    $this->freezeTime();
    $admin = adminUser();
    $group = ProductGroup::factory()->create();
    $product = Product::factory()->create(['product_group_id' => $group->id]);
    $plan = LicensePlan::factory()->create(['product_id' => $product->id]);
    $key = app(CreateBatch::class)->create($plan, 1, 'sale', 'test')->keys->first();
    app(ClaimLicense::class)->claim($admin, $key->key);
    $hwid = ['value' => 'pc-1'];
    $desktop = app(ClientSessions::class);
    $login = $desktop->login($product, $admin->email, 'password', $hwid);
    $tables = ['licensekit_license_plans', 'licensekit_license_keys', 'licensekit_entitlements', 'licensekit_device_bindings', 'client_sessions', 'personal_access_tokens'];
    $before = [];
    foreach ($tables as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->toArray();
    }
    Livewire::test(ManageProductGroups::class)->callTableAction('edit', $group, data: ['name' => 'Renamed'])->assertHasNoActionErrors();
    $group = ProductGroup::factory()->create();
    Livewire::test(ManageProducts::class)->callTableAction('edit', $product, data: ['product_group_id' => $group->id])->assertHasNoActionErrors();
    $productBefore = $product->fresh()->getAttributes();
    Livewire::test(ManageProductGroups::class)->callTableAction('delete', $group)->assertHasNoActionErrors();
    $this->assertDatabaseMissing('licensekit_product_groups', ['id' => $group->id]);
    expect($product->fresh()->getAttributes())->toBe([...$productBefore, 'product_group_id' => null]);
    foreach ($tables as $table) {
        expect(DB::table($table)->orderBy('id')->get()->toArray())->toEqual($before[$table]);
    }
    expect($desktop->heartbeat($product->fresh(), $login['token'], $hwid))->toHaveKey('expires_at');
    $audit = AuditEvent::where('event', 'configuration_deleted')->first();
    expect($audit->actor)->toBe('user:'.$admin->id);
    expect($audit->before)->toBe(['name' => $group->name, 'affected_product_count' => 1]);
});

test('permission removal after mounting blocks group deletion and creation', function () {
    adminUser();
    $group = ProductGroup::factory()->create();
    $delete = Livewire::test(ManageProductGroups::class)->mountTableAction('delete', $group);
    $create = Livewire::test(ManageProductGroups::class)->mountAction('create');
    Gate::define('licensekit.manage', fn () => false);
    $delete->callMountedTableAction()->assertForbidden();
    $create->callMountedAction()->assertForbidden();
    $this->assertDatabaseHas('licensekit_product_groups', ['id' => $group->id]);
    expect(AuditEvent::where('event', 'configuration_deleted')->count())->toBe(0);
});

test('failed deletion audit rolls back group deletion and product membership', function () {
    adminUser();
    $group = ProductGroup::factory()->create();
    $product = Product::factory()->create(['product_group_id' => $group->id]);
    DB::statement("CREATE TRIGGER reject_group_delete_audit BEFORE INSERT ON licensekit_audit_events WHEN NEW.event = 'configuration_deleted' BEGIN SELECT RAISE(FAIL, 'Audit unavailable'); END");
    expect(fn () => CatalogWrites::deleteGroup($group))->toThrow(QueryException::class);
    $this->assertDatabaseHas('licensekit_product_groups', ['id' => $group->id]);
    expect($product->fresh()->product_group_id)->toBe($group->id);
});

test('group names render escaped in group product and plan tables', function () {
    adminUser();
    $name = '<script>alert("group")</script>';
    $group = ProductGroup::factory()->create(['name' => $name]);
    $product = Product::factory()->create(['product_group_id' => $group->id]);
    LicensePlan::factory()->create(['product_id' => $product->id]);
    foreach ([ManageProductGroups::class, ManageProducts::class] as $page) {
        Livewire::test($page)->assertSee($name)->assertDontSee($name, escape: false);
    }
    Livewire::test(ManageLicensePlans::class)->assertCanNotRenderTableColumn('product.group.name');
});
