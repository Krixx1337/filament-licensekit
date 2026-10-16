<?php

namespace LicenseKit\Support;

use Illuminate\Support\Facades\DB;
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
        foreach (['licensekit_entitlements' => ['paused_at', 'remaining_seconds'], 'licensekit_device_reset_history' => ['before_hwid'], 'licensekit_license_keys' => ['note', 'batch_id'], 'licensekit_batches' => ['issuer', 'reference', 'quantity', 'duration_days', 'label', 'api_client_id', 'created_by_user_id']] as $table => $columns) {
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
        if (Schema::hasTable('licensekit_device_bindings')) {
            foreach (['user_id', 'scope_key', 'hwid', 'bound_at', 'last_hwid_reset_at'] as $column) {
                if (! Schema::hasColumn('licensekit_device_bindings', $column)) {
                    $errors[] = 'Missing device binding column: '.$column.'. Run php artisan migrate with the installed LicenseKit version.';
                }
            }
            if ($errors === []) {
                $bindings = DB::table('licensekit_device_bindings');
                $wrongScope = config('licensekit.device_binding.scope') === 'global'
                    ? $bindings->where('scope_key', '!=', 'global')->exists()
                    : $bindings->where('scope_key', 'not like', 'product:%')->exists();
                if ($wrongScope) {
                    $errors[] = 'Device bindings use another scope. Restore licensekit.device_binding.scope; automatic conversion/reset is not supported.';
                }
            }
        }

        return $errors;
    }
}
