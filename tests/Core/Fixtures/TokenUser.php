<?php

namespace LicenseKit\Tests\Core\Fixtures;

use Laravel\Sanctum\HasApiTokens;

class TokenUser extends User
{
    use HasApiTokens;
}
