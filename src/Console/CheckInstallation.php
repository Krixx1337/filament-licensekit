<?php

namespace LicenseKit\Console;

use Illuminate\Console\Command;
use LicenseKit\Support\SetupDiagnostics;

class CheckInstallation extends Command
{
    protected $signature = 'licensekit:check';

    protected $description = 'Check LicenseKit configuration, tables and signing without changing the application.';

    public function handle(SetupDiagnostics $diagnostics): int
    {
        $errors = $diagnostics->issues();
        foreach ($errors as $error) {
            $this->error($error);
        }
        if ($errors !== []) {
            return self::FAILURE;
        }
        $this->info('LicenseKit installation is ready.');

        return self::SUCCESS;
    }
}
