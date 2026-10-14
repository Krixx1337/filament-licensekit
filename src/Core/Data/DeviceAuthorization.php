<?php

namespace LicenseKit\Core\Data;

use Illuminate\Database\Eloquent\Model;

final readonly class DeviceAuthorization
{
    /** @param array<string, mixed> $hwid */
    public function __construct(public Model $user, public AccessAuthorization $access, public array $hwid) {}
}
