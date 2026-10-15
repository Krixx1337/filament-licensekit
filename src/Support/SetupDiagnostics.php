<?php

namespace LicenseKit\Support;

use Filament\Panel;
use Illuminate\Auth\AuthManager;
use InvalidArgumentException;
use LicenseKit\Core\Contracts\HwidHandler;
use LicenseKit\Core\Contracts\KeyNormalizer;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Users\ConfiguredUserModel;

final class SetupDiagnostics
{
    public function __construct(private AuthManager $auth) {}

    /** @return list<string> */
    public function configurationErrors(): array
    {
        $errors = [];
        if (! is_bool(config('licensekit.features.licensing_bans'))) {
            $errors[] = 'licensekit.features.licensing_bans must be true or false.';
        }
        try {
            ConfiguredUserModel::className();
            if ($this->auth->createUserProvider(ConfiguredUserModel::provider()) === null) {
                throw new InvalidArgumentException('LicenseKit requires a Laravel user provider.');
            }
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage().' Check licensekit.user.guard and auth.guards/auth.providers.';
        }
        foreach (['outages'] as $module) {
            if (! is_bool(config('licensekit.modules.'.$module))) {
                $errors[] = 'licensekit.modules.'.$module.' must be true or false.';
            }
        }
        $normalizer = config('licensekit.key_normalizer');
        if (! is_string($normalizer) || ! is_a($normalizer, KeyNormalizer::class, true)) {
            $errors[] = 'licensekit.key_normalizer must implement KeyNormalizer.';
        }
        if (! is_bool(config('licensekit.imports.enabled'))) {
            $errors[] = 'licensekit.imports.enabled must be true or false.';
        }
        $handler = config('licensekit.hwid_handler');
        if (! is_string($handler) || ! is_a($handler, HwidHandler::class, true)) {
            $errors[] = 'licensekit.hwid_handler must implement HwidHandler.';
        }
        if (! in_array(config('licensekit.device_binding.scope'), ['per_product', 'global'], true)) {
            $errors[] = 'licensekit.device_binding.scope must be per_product or global.';
        }
        if (! in_array(config('licensekit.device_binding.storage'), ['plain', 'encrypted'], true)) {
            $errors[] = 'licensekit.device_binding.storage must be plain or encrypted.';
        }
        if (config('licensekit.device_binding.storage') === 'encrypted') {
            try {
                app('encrypter');
            } catch (\Throwable) {
                $errors[] = 'Encrypted HWID storage requires a valid Laravel encryption key.';
            }
        }
        if (! is_bool(config('licensekit.device_binding.customer_reset.enabled'))) {
            $errors[] = 'licensekit.device_binding.customer_reset.enabled must be true or false.';
        }
        if (! is_bool(config('licensekit.device_binding.customer_reset.require_reason'))) {
            $errors[] = 'licensekit.device_binding.customer_reset.require_reason must be true or false.';
        }
        $cooldown = config('licensekit.device_binding.customer_reset.cooldown_days');
        if (! is_int($cooldown) || $cooldown < 0) {
            $errors[] = 'licensekit.device_binding.customer_reset.cooldown_days must be a nonnegative integer.';
        }
        if (! is_bool(config('licensekit.user.require_verified'))) {
            $errors[] = 'licensekit.user.require_verified must be true or false.';
        }
        foreach (['display_column', 'suspended_column'] as $column) {
            $value = config('licensekit.user.'.$column);
            if (($value === null && $column !== 'display_column') || (is_string($value) && trim($value) !== '')) {
                continue;
            }
            $errors[] = 'licensekit.user.'.$column.' must be a nonempty column name'.($column === 'display_column' ? '.' : ' or null.');
        }

        return [...$errors, ...$this->managementErrors()];
    }

    /** @return list<string> */
    public function managementErrors(): array
    {
        $settings = config('licensekit.management');
        if (! is_array($settings)) {
            return ['licensekit.management must be an array.'];
        }
        $errors = [];
        foreach (['enabled', 'prefix', 'domain', 'middleware', 'rate_limits'] as $option) {
            $value = $settings[$option] ?? null;
            $valid = match ($option) {
                'enabled' => is_bool($value),
                'prefix' => is_string($value) && ! preg_match('/[\s{}?#\\\\:]/', $value),
                'domain' => array_key_exists('domain', $settings) && ($value === null || (is_string($value) && $value !== '' && ! preg_match('/[\s{}\/?#]/', $value))),
                'middleware' => is_array($value) && array_is_list($value) && count(array_filter($value, fn ($item): bool => is_string($item) && trim($item) !== '')) === count($value),
                'rate_limits' => is_array($value) && is_int($value['reads'] ?? null) && $value['reads'] > 0 && is_int($value['writes'] ?? null) && $value['writes'] > 0,
            };
            if (! $valid) {
                $errors[] = 'Invalid licensekit.management.'.$option.'.';
            }
        }

        return $errors;
    }

    public function panelError(Panel $panel): ?string
    {
        if ($panel->hasTenancy()) {
            return 'Panel ['.$panel->getId().'] uses tenancy. Register LicenseKit on a non-tenant panel.';
        }
        $provider = config('auth.guards.'.$panel->getAuthGuard().'.provider');
        if (! is_string($provider) || config('auth.providers.'.$provider.'.model') !== ConfiguredUserModel::className()) {
            return 'Panel ['.$panel->getId().'] must use the configured LicenseKit host user model. Check its auth guard.';
        }

        return null;
    }

    public function assertConfigured(): void
    {
        if ($this->configurationErrors() !== []) {
            throw new LicensingException('licensing_unavailable', 503);
        }
    }
}
