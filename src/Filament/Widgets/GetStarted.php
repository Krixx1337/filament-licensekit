<?php

namespace LicenseKit\Filament\Widgets;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\LoaderSession;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Pages\ConnectYourApp;
use LicenseKit\Filament\Resources\LicenseKeys\LicenseKeyResource;
use LicenseKit\Filament\Resources\Products\ProductResource;

/** A three-step checklist that disappears once the seller has made a product, keys and a first app login. */
class GetStarted extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected string $view = 'licensekit::widgets.get-started';

    public static function canView(): bool
    {
        // Setup covers every product, so staff limited to some products never see it.
        return ! AdminAccess::isScoped() && in_array(false, array_column(self::steps(), 'done'), true);
    }

    /** @return list<array{done: bool, heading: string, description: string, button: string, url: string}> */
    public static function steps(): array
    {
        return [
            ['done' => Product::exists(), 'heading' => '1. Create a product', 'description' => 'Name what you sell and choose how long each plan lasts.',
                'button' => 'Create product', 'url' => ProductResource::getUrl()],
            ['done' => Batch::exists(), 'heading' => '2. Generate keys', 'description' => 'Make keys to sell or give away. Customers redeem them on your website or in your app.',
                'button' => 'Generate keys', 'url' => LicenseKeyResource::getUrl()],
            ['done' => LoaderSession::exists(), 'heading' => '3. Connect your app', 'description' => 'Copy the public key and example code into your app.'
                .' This step completes after the first login from your app.',
                'button' => 'Connect your app', 'url' => ConnectYourApp::getUrl()],
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([Section::make('Get started')->description('Three steps to your first sale.')->icon(Heroicon::OutlinedRocketLaunch)
            ->schema(array_map(fn (array $step): Callout => Callout::make($step['heading'])->description($step['description'])
                ->icon($step['done'] ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedArrowRightCircle)
                ->color($step['done'] ? 'success' : 'gray')
                ->actions($step['done'] ? [] : [Action::make('step'.$step['heading'][0])->label($step['button'])->url($step['url'])]), self::steps()))
            ->columns(['md' => 3])]);
    }
}
