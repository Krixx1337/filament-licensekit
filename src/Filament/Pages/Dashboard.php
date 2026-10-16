<?php

namespace LicenseKit\Filament\Pages;

use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\LicenseKey;
use LicenseKit\Core\Models\LoaderSession;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\Resources\LicenseKeys\LicenseKeyResource;
use LicenseKit\Filament\Resources\Products\ProductResource;

class Dashboard extends LicenseKitPage
{
    protected static ?string $slug = 'licensekit/dashboard';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = 10;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected string $view = 'licensekit::dashboard';

    /** @return array<string, int> */
    public function stats(): array
    {
        return ['Products' => Product::count(), 'Keys generated' => LicenseKey::count(),
            'Keys redeemed today' => LicenseKey::where('redeemed_at', '>=', now()->setTimezone(config('app.timezone'))->startOfDay()->utc())->count(),
            'Active paid licenses' => License::where('status', 'active')->where(fn ($q) => $q->where('is_lifetime', true)->orWhere('expires_at', '>', now()))->count()];
    }

    /** @return list<array{done: bool, label: string, url: string}> */
    public function steps(): array
    {
        return [
            ['done' => Product::exists(), 'label' => 'Create your first product and plan', 'url' => ProductResource::getUrl()],
            ['done' => Batch::exists(), 'label' => 'Generate your first keys', 'url' => LicenseKeyResource::getUrl()],
            ['done' => LoaderSession::exists(), 'label' => 'Connect your app', 'url' => ConnectYourApp::getUrl()],
        ];
    }
}
