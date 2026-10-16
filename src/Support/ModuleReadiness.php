<?php

namespace LicenseKit\Support;

use Illuminate\Support\Facades\Schema;
use LicenseKit\Core\Contracts\ReadinessCheck;
use LicenseKit\Core\Exceptions\LicensingException;

final class ModuleReadiness implements ReadinessCheck
{
    public function __construct(private SetupDiagnostics $diagnostics) {}

    public function assertReady(): void
    {
        if ($this->diagnostics->configurationErrors() !== []) {
            throw new LicensingException('licensing_unavailable', 503);
        }
    }

    /** @return list<string> */
    public function issues(): array
    {
        $errors = $this->diagnostics->configurationErrors();
        if ($errors !== []) {
            return $errors;
        }
        foreach (['licensekit_entitlements' => ['paused_at', 'remaining_seconds', 'device', 'device_reset_at'], 'licensekit_products' => ['device_move_cooldown_days'], 'licensekit_license_keys' => ['note', 'batch_id'], 'licensekit_batches' => ['issuer', 'reference', 'quantity', 'duration_days', 'label', 'api_client_id', 'created_by_user_id']] as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $errors[] = 'Missing core table: '.$table.'. Run php artisan migrate with the installed LicenseKit version.';

                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $errors[] = 'Missing '.$table.' column: '.$column.'. Run php artisan migrate with the installed LicenseKit version.';
                }
            }
        }

        return $errors;
    }
}
