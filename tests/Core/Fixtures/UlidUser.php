<?php

namespace LicenseKit\Tests\Core\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

class UlidUser extends User
{
    use HasUlids;
}
