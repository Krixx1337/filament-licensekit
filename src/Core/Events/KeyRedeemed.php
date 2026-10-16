<?php

namespace LicenseKit\Core\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class KeyRedeemed implements ShouldDispatchAfterCommit
{
    public function __construct(public int $keyId, public int $licenseId) {}
}
