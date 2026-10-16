<?php

namespace LicenseKit\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use LicenseKit\Core\Actions\AccessPolicy;
use LicenseKit\Core\Actions\DeviceBans;
use LicenseKit\Core\Actions\ResponseSigning;
use LicenseKit\Core\Contracts\DeviceMatcher;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Product;
use LicenseKit\Core\Users\ConfiguredUserModel;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\Navigation;
use Livewire\Attributes\Url;

class ConnectYourApp extends LicenseKitPage
{
    protected static ?string $slug = 'licensekit/connect-your-app';

    protected static string|\UnitEnum|null $navigationGroup = Navigation::SETUP;

    protected static ?int $navigationSort = 10;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-code-bracket';

    protected static ?string $navigationLabel = 'Connect your app';

    protected string $view = 'licensekit::connect-your-app';

    #[Url(as: 'product')]
    public ?int $productId = null;

    public ?string $diagnosis = null;

    protected function getHeaderActions(): array
    {
        return [Action::make('check_customer')->label('Check a customer')->schema([
            LicensingActions::userSelect()->label('Customer'),
            Select::make('product_id')->label('Product')->options(fn () => Product::pluck('name', 'id'))->default(fn () => $this->productId)->required(),
            TextInput::make('device')->label('Device (optional)')->maxLength(1024),
        ])->action(function (array $data): void {
            $user = ConfiguredUserModel::className()::findOrFail($data['user_id']);
            $product = Product::whereKey($data['product_id'])->firstOrFail();
            try {
                $access = app(AccessPolicy::class)->authorize($user, $product);
                if (! empty($data['device'])) {
                    app(DeviceBans::class)->assertAllowed($product, $data['device']);
                    if ($product->device_lock && $access->license?->device !== null && ! app(DeviceMatcher::class)->matches($product, $access->license->device, $data['device'])) {
                        throw new LicensingException('device_mismatch');
                    }
                }
                $this->diagnosis = $access->validUntil === null ? 'Allowed: lifetime access.' : 'Allowed until '.$access->validUntil->setTimezone(config('app.timezone'))->format('j M Y, H:i T');
            } catch (LicensingException $exception) {
                $this->diagnosis = $exception->friendlyMessage();
            }
        })];
    }

    protected function getViewData(): array
    {
        $products = Product::orderBy('name')->get();
        $product = $products->firstWhere('id', $this->productId) ?? $products->first();
        try {
            $publicKey = app(ResponseSigning::class)->publicKey();
        } catch (LicensingException) {
            $publicKey = 'Run php artisan licensekit:install';
        }
        $base = $product === null ? '' : url(config('licensekit.client_prefix').'/'.$product->slug);
        $snippets = [];
        foreach (['Python' => 'client.py', 'C#' => 'Client.cs', 'C++' => 'client.cpp', 'curl' => 'client.sh'] as $label => $file) {
            $snippets[$label] = str_replace(['__BASE__', '__PUBLIC_KEY__'], [$base, $publicKey], (string) file_get_contents(__DIR__.'/../../../resources/snippets/'.$file));
        }

        return compact('products', 'product', 'publicKey', 'base', 'snippets');
    }
}
