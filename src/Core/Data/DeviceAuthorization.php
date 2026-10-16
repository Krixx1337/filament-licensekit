<?php

namespace LicenseKit\Core\Data;

use Illuminate\Database\Eloquent\Model;

final readonly class DeviceAuthorization
{
    public function __construct(public Model $user, public AccessAuthorization $access, public string $device) {}
}
