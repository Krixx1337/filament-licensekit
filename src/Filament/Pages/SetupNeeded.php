<?php

namespace LicenseKit\Filament\Pages;

use Filament\Facades\Filament;
use LicenseKit\Support\SetupDiagnostics;

class SetupNeeded extends LicenseKitPage
{
    protected static ?string $slug = 'licensekit/setup';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Setup needed';

    protected string $view = 'licensekit::setup-needed';

    /** @return list<string> */
    public function problems(): array
    {
        $diagnostics = app(SetupDiagnostics::class);
        $errors = $diagnostics->issues();
        try {
            $error = $diagnostics->panelError(Filament::getCurrentPanel());
            if ($error !== null) {
                $errors[] = $error;
            }
        } catch (\InvalidArgumentException) {
            // The configuration issue is already in the list.
        }

        return $errors;
    }
}
