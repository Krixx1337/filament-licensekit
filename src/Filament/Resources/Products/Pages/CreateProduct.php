<?php

namespace LicenseKit\Filament\Resources\Products\Pages;

use Filament\Resources\Pages\CreateRecord;
use LicenseKit\Core\Actions\ActivityLog;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Concerns\DisplaysLicensingErrors;
use LicenseKit\Filament\Resources\Products\ProductResource;

class CreateProduct extends CreateRecord
{
    use DisplaysLicensingErrors;

    protected static string $resource = ProductResource::class;

    protected static bool $canCreateAnother = false;

    protected ?bool $hasDatabaseTransactions = true;

    protected function afterCreate(): void
    {
        app(ActivityLog::class)->record('user:'.AdminAccess::actor()->getKey(), 'product_created', $this->getRecord(), ['after' => $this->getRecord()->toArray()]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Product created. Next, generate keys for it.';
    }

    protected function getRedirectUrl(): string
    {
        return ProductResource::getUrl('index');
    }
}
