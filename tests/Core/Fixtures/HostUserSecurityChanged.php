<?php

namespace LicenseKit\Tests\Core\Fixtures;

use Illuminate\Database\Eloquent\Model;

final readonly class HostUserSecurityChanged
{
    public function __construct(public Model $user) {}
}
