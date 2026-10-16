<?php

namespace LicenseKit\Filament\Resources\ApiConnections\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Concerns\DisplaysLicensingErrors;
use LicenseKit\Filament\Concerns\ShowsResult;
use LicenseKit\Filament\Resources\ApiConnections\ApiConnectionResource;

class ManageApiConnections extends ManageRecords
{
    use DisplaysLicensingErrors;
    use ShowsResult;

    protected static string $resource = ApiConnectionResource::class;

    public function boot(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (config('licensekit.store_api_enabled') === true) {
            return null;
        }

        return 'Management API disabled in host configuration.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->createAnother(false)->action(function (array $data, $livewire): void {
            $token = DB::transaction(function () use ($data): string {
                $service = app(\LicenseKit\Core\Actions\ManageApiConnections::class);
                $connection = $service->save(null, $data, AdminAccess::actor());

                return $service->regenerate($connection, AdminAccess::actor());
            });
            $livewire->replaceMountedAction('result', ['title' => 'Connection token', 'text' => $token, 'token' => true]);
        })];
    }
}
