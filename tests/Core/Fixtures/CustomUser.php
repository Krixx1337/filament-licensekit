<?php

namespace LicenseKit\Tests\Core\Fixtures;

class CustomUser extends User
{
    protected $table = 'members';

    protected static function newFactory(): UserFactory
    {
        return CustomUserFactory::new();
    }
}
