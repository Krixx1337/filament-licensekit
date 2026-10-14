<?php

namespace LicenseKit\Core\Contracts;

interface ReadinessCheck
{
    public function assertReady(): void;
}
