<?php

use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\Pages\ConnectYourApp;
use LicenseKit\Filament\Pages\TeamAccess;
use LicenseKit\Tests\Filament\Fixtures\User;
use Livewire\Livewire;

test('connect your app shows readiness, key, addresses and code for the chosen product', function () {
    adminUser();
    Product::factory()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $beta = Product::factory()->create(['name' => 'Beta', 'slug' => 'beta']);

    Livewire::withQueryParams(['product' => $beta->id])->test(ConnectYourApp::class)
        ->assertSee(['Loader API', 'Signing key', 'Ready', 'First app login', 'Not yet', '1. Copy your public key'])
        ->assertSee(url('api/licensekit/beta/login'))->assertDontSee(url('api/licensekit/alpha/login'))
        ->assertSee(['Python', 'C#', 'pip install requests pynacl', 'What customers may see'])
        // C# and C++ once shared a tab key, so they opened and closed together.
        ->assertSee(['snippet-client-cs', 'snippet-client-cpp']);
});

test('connect your app asks for a product first', function () {
    adminUser();

    Livewire::test(ConnectYourApp::class)->assertSee('Create a product first');
});

test('connect check explains why a customer is refused', function () {
    adminUser();
    $customer = User::factory()->create();
    $product = Product::factory()->create();

    Livewire::test(ConnectYourApp::class)->callAction('check_customer', data: ['user_id' => $customer->id, 'product_id' => $product->id])
        ->assertNotified('Not allowed');
});

test('team access explains both roles in plain words', function () {
    adminUser();

    Livewire::test(TeamAccess::class)->assertSee(['Admin', 'Support', 'Generate keys', 'Add time, pause and resume', 'For your developer']);
});
