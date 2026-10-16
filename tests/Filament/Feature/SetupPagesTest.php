<?php

use Filament\Actions\Testing\TestAction;
use LicenseKit\Core\Actions\ResponseSigning;
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
        ->assertSee(['Python', 'C#', 'What customers may see', 'Copy full example', 'Download client.py'])
        ->assertSee('only run your software while this is True')
        // C# and C++ once shared a tab key, so they opened and closed together.
        ->assertSee(['snippet-csharp', 'snippet-cpp']);
});

test('the full example downloads with the product address and public key filled in', function () {
    adminUser();
    $product = Product::factory()->create(['slug' => 'gamma']);

    Livewire::withQueryParams(['product' => $product->id])->test(ConnectYourApp::class)
        ->callAction(TestAction::make('download_python')->schemaComponent('snippet-python.code_actions_python', schema: 'content'))
        ->assertFileDownloaded('client.py', content: str_replace(['__BASE__', '__PUBLIC_KEY__'], [url('api/licensekit/gamma'), app(ResponseSigning::class)->publicKey()],
            trim(str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../../../resources/snippets/client.py')))));
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
