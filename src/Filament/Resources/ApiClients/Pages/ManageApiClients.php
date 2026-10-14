<?php

namespace LicenseKit\Filament\Resources\ApiClients\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;
use LicenseKit\Filament\Concerns\DisplaysLicensingErrors;
use LicenseKit\Filament\Resources\ApiClients\ApiClientResource;

class ManageApiClients extends ManageRecords
{
    use DisplaysLicensingErrors;

    protected static string $resource = ApiClientResource::class;

    public function boot(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (config('licensekit.management.enabled') === true) {
            return null;
        }

        return 'Management API disabled in host configuration.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->createAnother(false)->action(fn (array $data) => ApiClientResource::createWithToken($data))];
    }
}
