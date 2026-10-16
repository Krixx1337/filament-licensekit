<?php

namespace LicenseKit\Console;

use Filament\FilamentManager;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\SQLiteDatabaseDoesNotExistException;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Users\ConfiguredUserModel;
use LicenseKit\Support\ModuleReadiness;
use LicenseKit\Support\SetupDiagnostics;
use PDOException;

class CheckInstallation extends Command
{
    protected $signature = 'licensekit:check';

    protected $description = 'Check LicenseKit configuration and required tables without changing the application.';

    public function handle(SetupDiagnostics $diagnostics, ModuleReadiness $readiness): int
    {
        $errors = $diagnostics->configurationErrors();
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }
            $this->line('Correct config/licensekit.php or the host auth configuration, then run php artisan config:clear.');

            return self::FAILURE;
        }
        if ($this->laravel->bound('filament')) {
            foreach ($this->laravel->make(FilamentManager::class)->getPanels() as $panel) {
                if (! $panel->hasPlugin('licensekit')) {
                    continue;
                }
                if (($error = $diagnostics->panelError($panel)) !== null) {
                    $this->error($error);

                    return self::FAILURE;
                }
            }
        }
        $user = new (ConfiguredUserModel::className());
        try {
            return $this->checkDatabase($user, $readiness);
        } catch (PDOException|SQLiteDatabaseDoesNotExistException) {
            $this->error('Database unavailable. Check the host database connection settings and connectivity.');

            return self::FAILURE;
        }
    }

    private function checkDatabase(Model&Authenticatable $user, ModuleReadiness $readiness): int
    {
        $this->info('Laravel user integration: '.ConfiguredUserModel::guard().' / '.ConfiguredUserModel::provider().' / '.$user::class);
        if (config('licensekit.user.require_verified') && ! $user instanceof MustVerifyEmail) {
            $this->error('Verified users require a model implementing MustVerifyEmail.');

            return self::FAILURE;
        }
        $tables = [$user->getTable(), (new License)->getTable(), (new (Sanctum::personalAccessTokenModel()))->getTable(),
            'licensekit_products', 'licensekit_license_plans', 'licensekit_license_keys',
            'licensekit_api_clients', 'licensekit_batches', 'licensekit_audit_events'];
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                $this->error('Missing table: '.$table.'. Prepare host/Sanctum migrations and run php artisan migrate.');

                return self::FAILURE;
            }
        }
        $columns = [$user->getKeyName(), config('licensekit.user.display_column')];
        $suspendedAttribute = config('licensekit.user.suspended_column');
        if ($suspendedAttribute !== null && ! $user->hasAnyGetMutator($suspendedAttribute)) {
            $columns[] = $suspendedAttribute;
        }
        foreach ($columns as $column) {
            if (! Schema::hasColumn($user->getTable(), $column)) {
                $this->error('Missing user column: '.$column.'. Check licensekit.user configuration.');

                return self::FAILURE;
            }
        }
        $issues = $readiness->issues();
        if ($issues !== []) {
            foreach ($issues as $issue) {
                $this->error($issue);
            }

            return self::FAILURE;
        }
        $this->info('LicenseKit installation is ready.');

        return self::SUCCESS;
    }
}
