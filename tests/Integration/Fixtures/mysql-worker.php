<?php

require __DIR__.'/../../../vendor/autoload.php';

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\Keys;
use LicenseKit\Core\Data\IssueContext;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Tests\Integration\Fixtures\User;
use LicenseKit\Tests\Integration\MySqlTestCase;

$case = new MySqlTestCase('worker');
$case->createApplication();
$payload = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
$case->travelTo(CarbonImmutable::parse($payload['at']));
$worker = DB::table('licensekit_test_barrier')->insertGetId([]);
if ($payload['old_snapshot'] ?? false) {
    DB::beginTransaction();
    Batch::count();
}
$deadline = microtime(true) + 15;
while (DB::table('licensekit_test_barrier')->sharedLock()->count() < 2) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Race barrier timed out.');
    }
    usleep(10000);
}
try {
    if ($argv[1] === 'redeem') {
        $license = app(ClaimLicense::class)->claim(User::findOrFail($payload['user_id']), $payload['keys'][$worker - 1]);
        $result = ['status' => 'ok', 'id' => $license->id];
    } else {
        $batch = app(Keys::class)->issue(LicensePlan::findOrFail($payload['plan_id']), 2, new IssueContext(reference: 'same-order'));
        $result = ['status' => 'ok', 'id' => $batch->id, 'keys' => $batch->keys->sortBy('id')->pluck('key')->all()];
    }
    if ($payload['old_snapshot'] ?? false) {
        DB::commit();
    }
} catch (LicensingException $exception) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $result = ['status' => 'error', 'error' => $exception->error];
}
echo json_encode(['result' => $result], JSON_THROW_ON_ERROR);
