<?php

use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\Resources\Products\Pages\ManageProducts;
use Livewire\Livewire;

test('product form creates inline plans and device settings with optional free window', function () {
    adminUser();
    config(['app.timezone' => 'Europe/Warsaw']);
    Livewire::test(ManageProducts::class)->callAction('create', data: [
        'name' => 'Software', 'slug' => 'software', 'status' => 'active', 'device_lock' => true,
        'device_move_cooldown_days' => 14, 'plans' => [['name' => 'Monthly', 'duration_preset' => '30', 'duration_days' => 30, 'is_active' => true]],
        'free_from' => '2026-11-01 18:00:00', 'free_until' => '2026-11-03 18:00:00',
    ])->assertHasNoActionErrors();
    $product = Product::sole();
    expect($product->device_lock)->toBeTrue()->and($product->device_move_cooldown_days)->toBe(14);
    expect($product->plans()->sole()->duration_days)->toBe(30);
    expect($product->free_from->toIso8601String())->toBe('2026-11-01T17:00:00+00:00');
});

test('product form rejects reversed free window', function () {
    adminUser();
    Livewire::test(ManageProducts::class)->callAction('create', data: ['name' => 'Bad', 'slug' => 'bad', 'status' => 'active',
        'device_lock' => false, 'plans' => [], 'free_from' => '2026-11-03 18:00:00', 'free_until' => '2026-11-01 18:00:00'])
        ->assertHasActionErrors(['free_until']);
    $this->assertDatabaseCount('licensekit_products', 0);
});
