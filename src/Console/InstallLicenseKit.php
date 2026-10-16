<?php

namespace LicenseKit\Console;

use ErrorException;
use Illuminate\Console\Command;
use LicenseKit\Core\Users\ConfiguredUserModel;
use LicenseKit\Support\SetupDiagnostics;

class InstallLicenseKit extends Command
{
    protected $signature = 'licensekit:install';

    protected $description = 'Publish missing LicenseKit configuration and explain host integration steps.';

    public function handle(SetupDiagnostics $diagnostics): int
    {
        if (! is_file(config_path('licensekit.php'))) {
            try {
                $published = $this->call('vendor:publish', ['--tag' => 'licensekit-config']) === self::SUCCESS
                    && is_file(config_path('licensekit.php'));
            } catch (ErrorException|\RuntimeException) {
                $published = false;
            }
            if (! $published) {
                $this->error('LicenseKit configuration could not be published. Check config directory permissions and retry.');

                return self::FAILURE;
            }
        } else {
            $this->info('Existing config/licensekit.php preserved.');
        }
        $errors = $diagnostics->configurationErrors();
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }
            $this->line('Correct config/licensekit.php or the host auth configuration, then run php artisan config:clear.');

            return self::FAILURE;
        }
        $this->info('Host user: '.ConfiguredUserModel::className());
        $this->line('Register LicenseKit\LicenseKitPlugin::make() in your existing non-tenant Filament panel.');
        $this->line('Every panel user is a LicenseKit admin by default. Define the licensekit.admin and licensekit.support gates to restrict staff.');
        $this->line('Prepare your host users and Sanctum personal_access_tokens migrations, then run php artisan migrate.');
        $this->line('Run php artisan licensekit:check after migration. Module changes require rebuilding caches and restarting workers.');

        return self::SUCCESS;
    }
}
