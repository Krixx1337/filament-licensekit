<?php

use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Actions\Audit;
use LicenseKit\Modules\Outages\Jobs\ApplyCompensation;
use LicenseKit\Modules\Outages\Models\LicensingOperation;
use LicenseKit\Tests\Outages\Fixtures\User;
use LicenseKit\Tests\Outages\MySqlTestCase;
use LicenseKit\Tests\Outages\TestCase;

pest()->extend(TestCase::class)->in('Outages/Feature');

pest()->extend(MySqlTestCase::class)->in('Outages/Integration');

function outageAdmin(): User
{
    $admin = User::factory()->create();
    Gate::define('licensekit.manage', fn ($actor): bool => $actor->getKey() === $admin->getKey());

    return $admin;
}

function applyOperation(LicensingOperation $operation): void
{
    (new ApplyCompensation($operation->id))->handle(app(Audit::class));
}
