<?php

namespace LicenseKit\Support;

use Filament\FilamentManager;
use Filament\Panel;
use Illuminate\Auth\AuthManager;
use Illuminate\Support\Facades\Schema;
use LicenseKit\Core\Actions\ResponseSigning;
use LicenseKit\Core\Contracts\DeviceMatcher;
use LicenseKit\Core\Users\ConfiguredUserModel;
use Throwable;

final class SetupDiagnostics
{
    public function __construct(private AuthManager $auth) {}

    /** @return list<string> */
    public function configurationErrors(): array
    {
        $errors = [];
        try {
            ConfiguredUserModel::className();
            if ($this->auth->createUserProvider(ConfiguredUserModel::provider()) === null) {
                $errors[] = 'Configure a Laravel user provider.';
            }
        } catch (\InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        }
        $matcher = config('licensekit.device_matcher');
        if (! is_string($matcher) || ! is_a($matcher, DeviceMatcher::class, true)) {
            $errors[] = 'licensekit.device_matcher must implement DeviceMatcher.';
        }
        foreach (['client', 'store_api', 'portal_api'] as $api) {
            if (! is_bool(config('licensekit.'.$api.'_enabled'))) {
                $errors[] = 'licensekit.'.$api.'_enabled must be true or false.';
            }
            $prefix = config('licensekit.'.$api.'_prefix');
            if (! is_string($prefix) || trim($prefix, '/') === '' || preg_match('/[\s{}?#\\\\:]/', $prefix)) {
                $errors[] = 'licensekit.'.$api.'_prefix must be a URL path.';
            }
        }
        foreach (['user_label', 'client_login_column', 'user_suspended_column'] as $column) {
            $value = config('licensekit.'.$column);
            if ($value === null && $column === 'user_suspended_column') {
                continue;
            }
            if (! is_string($value) || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $value)) {
                $errors[] = 'licensekit.'.$column.' must be a column name.';
            }
        }
        if (! is_int(config('licensekit.max_batch_size')) || config('licensekit.max_batch_size') < 1) {
            $errors[] = 'licensekit.max_batch_size must be a positive integer.';
        }

        return $errors;
    }

    /** @return list<string> */
    public function issues(bool $includeSigning = true): array
    {
        $errors = $this->configurationErrors();
        if ($errors !== []) {
            return $errors;
        }
        if (app()->bound('filament')) {
            foreach (app(FilamentManager::class)->getPanels() as $panel) {
                if ($panel->hasPlugin('licensekit') && ($error = $this->panelError($panel)) !== null) {
                    $errors[] = $error;
                }
            }
        }
        try {
            foreach (['products', 'plans', 'batches', 'keys', 'licenses', 'loader_sessions', 'device_bans', 'api_connections', 'activity_log'] as $table) {
                if (! Schema::hasTable('licensekit_'.$table)) {
                    $errors[] = 'Missing table: licensekit_'.$table.'. Run php artisan migrate.';
                }
            }
            $user = new (ConfiguredUserModel::className());
            foreach (array_filter([$user->getKeyName(), config('licensekit.user_label'), config('licensekit.user_suspended_column')]) as $column) {
                if (! $user->hasAnyGetMutator($column) && ! Schema::hasColumn($user->getTable(), $column)) {
                    $errors[] = 'Missing user column: '.$column.'. Check LicenseKit configuration.';
                }
            }
        } catch (Throwable) {
            $errors[] = 'Database unavailable. Check connection settings and run migrations.';
        }
        if ($includeSigning && config('licensekit.client_enabled')) {
            try {
                app(ResponseSigning::class)->publicKey();
            } catch (Throwable) {
                $errors[] = 'Response signing is not ready. Run php artisan licensekit:install.';
            }
        }

        return $errors;
    }

    public function panelError(Panel $panel): ?string
    {
        if ($panel->hasTenancy()) {
            return 'Register LicenseKit on a non-tenant panel.';
        }
        $provider = config('auth.guards.'.$panel->getAuthGuard().'.provider');
        if (! is_string($provider) || config('auth.providers.'.$provider.'.model') !== ConfiguredUserModel::className()) {
            return 'Panel must use the configured LicenseKit user model.';
        }

        return null;
    }
}
