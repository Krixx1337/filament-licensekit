<?php

use Filament\Forms\Components\Repeater;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\Resources\Products\Pages\CreateProduct;
use LicenseKit\Filament\Resources\Products\Pages\EditProduct;
use LicenseKit\Filament\Resources\Products\Pages\ListProducts;
use Livewire\Livewire;

test('product form creates inline plans and device settings with optional free window', function () {
    adminUser();
    config(['app.timezone' => 'Europe/Warsaw']);
    $undoRepeaterFake = Repeater::fake();
    Livewire::test(CreateProduct::class)->fillForm([
        'name' => 'Software', 'slug' => 'software', 'status' => 'active', 'device_lock' => true,
        'device_move_cooldown_days' => 14, 'plans' => [['name' => 'Monthly', 'duration_preset' => '30', 'duration_days' => 30, 'is_active' => true]],
        'free_from' => '2026-11-01 18:00:00', 'free_until' => '2026-11-03 18:00:00',
    ])->call('create')->assertHasNoFormErrors();
    $undoRepeaterFake();
    $product = Product::sole();
    expect($product->device_lock)->toBeTrue()->and($product->device_move_cooldown_days)->toBe(14);
    expect($product->plans()->sole()->duration_days)->toBe(30);
    expect($product->free_from->toIso8601String())->toBe('2026-11-01T17:00:00+00:00');
});

test('product form rejects reversed free window', function () {
    adminUser();
    Livewire::test(CreateProduct::class)->fillForm(['name' => 'Bad', 'slug' => 'bad', 'status' => 'active',
        'device_lock' => false, 'plans' => [], 'free_from' => '2026-11-03 18:00:00', 'free_until' => '2026-11-01 18:00:00'])
        ->call('create')->assertHasFormErrors(['free_until']);
    $this->assertDatabaseCount('licensekit_products', 0);
});

test('editing a product keeps its plans and records the change', function () {
    $admin = adminUser();
    $product = Product::factory()->hasPlans(1, ['name' => 'Lifetime', 'duration_days' => null])->create(['name' => 'Old name']);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertSchemaStateSet(['name' => 'Old name'])
        ->fillForm(['name' => 'New name', 'status' => 'maintenance'])->call('save')->assertHasNoFormErrors();

    expect($product->fresh())->name->toBe('New name')->status->toBe(Product::MAINTENANCE);
    expect($product->plans()->sole()->duration_days)->toBeNull();
    $this->assertDatabaseHas('licensekit_activity_log', ['actor' => 'user:'.$admin->id, 'event' => 'product_saved']);
});

test('the product list shows status, free weekend and an empty state', function () {
    adminUser();
    Livewire::test(ListProducts::class)->assertSee(['Create your first product', 'A product is what you sell']);

    $product = Product::factory()->create(['status' => Product::MAINTENANCE, 'free_from' => now()->subHour(), 'free_until' => now()->addDay()]);
    Livewire::test(ListProducts::class)->assertCanSeeTableRecords([$product])->assertSee(['Maintenance', 'On now, until']);
});
