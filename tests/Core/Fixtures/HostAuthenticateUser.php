<?php

namespace LicenseKit\Tests\Core\Fixtures;

use Illuminate\Auth\AuthManager;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LicenseKit\Core\Users\ConfiguredUserModel;
use LicenseKit\Core\Users\UserAccess;

class HostAuthenticateUser
{
    public function __construct(private AuthManager $auth, private UserAccess $users) {}

    public function authenticate(string $identity, #[\SensitiveParameter] string $password, bool $rehashPassword = false): ?Model
    {
        $model = ConfiguredUserModel::className();
        $provider = $this->auth->createUserProvider(ConfiguredUserModel::provider());
        if ($provider === null) {
            throw new InvalidArgumentException('LicenseKit requires a Laravel user provider.');
        }
        $identity = mb_strtolower(trim($identity));
        $column = str_contains($identity, '@') ? 'email' : config('host_client.username_column', 'username');
        if (! is_string($column) || $column === '') {
            return null;
        }
        $credentials = [$column => $identity, 'password' => $password];
        $user = $provider->retrieveByCredentials($credentials);
        if ($user === null) {
            return null;
        }
        if (! $user instanceof $model) {
            throw new InvalidArgumentException('Laravel user provider returned an unsupported LicenseKit user model.');
        }
        if (! $provider->validateCredentials($user, $credentials) || $this->users->suspended($user)) {
            return null;
        }

        return $user;
    }
}
