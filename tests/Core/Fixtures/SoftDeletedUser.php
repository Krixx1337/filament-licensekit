<?php

namespace LicenseKit\Tests\Core\Fixtures;

use Illuminate\Database\Eloquent\SoftDeletes;

class SoftDeletedUser extends User
{
    use SoftDeletes;
}
