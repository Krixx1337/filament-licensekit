<?php

namespace LicenseKit\Filament\Resources\Products\Pages;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use LicenseKit\Core\Actions\ActivityLog;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Concerns\DisplaysLicensingErrors;
use LicenseKit\Filament\Concerns\ShowsResult;
use LicenseKit\Filament\Pages\ConnectYourApp;
use LicenseKit\Filament\Resources\Products\ProductResource;

/** @extends EditRecord<Product> */
class EditProduct extends EditRecord
{
    use DisplaysLicensingErrors;
    use ShowsResult;

    protected static string $resource = ProductResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function getHeaderActions(): array
    {
        return [
            LicensingActions::createKeys(),
            ActionGroup::make([LicensingActions::addTime(), Action::make('connect')->label('Connect your app')->icon(Heroicon::OutlinedCodeBracket)
                ->url(fn (): string => ConnectYourApp::getUrl(['product' => $this->getRecord()->id]))])
                ->label('More')->button()->color('gray')->icon(Heroicon::OutlinedChevronDown)->iconPosition('after'),
        ];
    }

    protected function afterSave(): void
    {
        app(ActivityLog::class)->record('user:'.AdminAccess::actor()->getKey(), 'product_saved', $this->getRecord(), ['after' => $this->getRecord()->toArray()]);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Product saved';
    }
}
