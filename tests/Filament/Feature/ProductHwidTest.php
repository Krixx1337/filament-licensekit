<?php

use Illuminate\Validation\ValidationException;
use LicenseKit\Core\Models\AuditEvent;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\CatalogWrites;
use LicenseKit\Filament\Resources\Products\Pages\ManageProducts;
use Livewire\Livewire;

test('product forms default to HWID protection and persist audited toggle changes', function () {
    $actor = adminUser();
    Livewire::test(ManageProducts::class)->callAction('create', data: ['name' => 'Protected', 'slug' => 'protected'])->assertHasNoActionErrors();
    $product = Product::firstOrFail();
    expect($product->requires_hwid)->toBeTrue();

    Livewire::test(ManageProducts::class)->callTableAction('edit', $product, data: ['requires_hwid' => false])->assertHasNoActionErrors();

    expect($product->fresh()->requires_hwid)->toBeFalse();
    $audit = AuditEvent::where('event', 'configuration_updated')->where('subject_type', Product::class)->where('subject_id', $product->id)->firstOrFail();
    expect($audit->before)->toBe(['requires_hwid' => true]);
    expect($audit->after)->toBe(['requires_hwid' => false]);
    expect($audit->actor)->toBe('user:'.$actor->id);

    Livewire::test(ManageProducts::class)->callAction('create', data: ['name' => 'Utility', 'slug' => 'utility', 'requires_hwid' => false])->assertHasNoActionErrors();
    expect(Product::where('slug', 'utility')->firstOrFail()->requires_hwid)->toBeFalse();
});

test('catalog rejects invalid HWID toggle before mutation', function (mixed $value) {
    adminUser();
    $product = Product::factory()->create();

    expect(fn () => CatalogWrites::update($product, ['requires_hwid' => $value]))->toThrow(ValidationException::class);

    expect($product->fresh()->requires_hwid)->toBeTrue();
    $this->assertDatabaseMissing('licensekit_audit_events', ['event' => 'configuration_updated', 'subject_id' => $product->id]);
})->with([null, 'invalid', 2]);
