<?php

namespace LicenseKit\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use LicenseKit\Support\SetupDiagnostics;

class SetupNeeded extends LicenseKitPage
{
    protected static ?string $slug = 'licensekit/setup';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Setup needed';

    protected ?string $subheading = 'Finish these steps, then reopen LicenseKit.';

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

    public function content(Schema $schema): Schema
    {
        $problems = $this->problems();
        $callouts = $problems === []
            ? [Callout::make('Setup complete')->success()->description('Open the dashboard to continue.')]
            : array_map(fn (string $problem): Callout => Callout::make($problem)->warning()->icon(Heroicon::OutlinedExclamationTriangle), $problems);

        return $schema->components([...$callouts, Text::make('Your developer can run php artisan licensekit:check for the same checks.')]);
    }
}
