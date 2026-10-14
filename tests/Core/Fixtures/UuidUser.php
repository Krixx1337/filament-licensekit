<?php

namespace LicenseKit\Tests\Core\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class UuidUser extends User
{
    use HasUuids;
}
