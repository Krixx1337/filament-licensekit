<?php

require __DIR__.'/../../../vendor/autoload.php';
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Actions\Audit;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Actions\DeviceBindings;
use LicenseKit\Core\Actions\LicensedDevices;
use LicenseKit\Core\Actions\ManageApiClients;
use LicenseKit\Core\Actions\ManageEntitlements;
use LicenseKit\Core\Actions\ManageLicensingBans;
use LicenseKit\Core\Actions\ManagementOperations;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\ApiClient;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Modules\Outages\Actions\ManageOutages;
use LicenseKit\Modules\Outages\Jobs\ApplyCompensation;
use LicenseKit\Modules\Outages\Models\LicensingOperation;
use LicenseKit\Modules\Outages\Models\Outage;
use LicenseKit\Tests\Core\Fixtures\ClientSessions;
use LicenseKit\Tests\Core\Fixtures\StructuredHwid;
use LicenseKit\Tests\Outages\Fixtures\User;
use LicenseKit\Tests\Outages\MySqlTestCase;

$case = new MySqlTestCase('worker');
$case->createApplication();
$payload = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
$case->travelTo(CarbonImmutable::parse($payload['at']));
config(['licensekit.device_binding.scope' => $payload['binding_scope'] ?? 'per_product', 'host_client.free_until' => $payload['free_until'] ?? null]);
if ($payload['structured_hwid'] ?? false) {
    config(['licensekit.hwid_handler' => StructuredHwid::class]);
}
$worker = DB::table('licensekit_test_barrier')->insertGetId([]);
$deadline = microtime(true) + 15;
while (DB::table('licensekit_test_barrier')->count() < 2) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrent worker did not reach barrier.');
    }
    usleep(10000);
}
$operation = $argv[1];
$hwid = ['value' => 'pc'];
Gate::define('licensekit.manage', fn ($actor): bool => $actor->getKey() === ($payload['actor_id'] ?? null) || in_array($actor->getKey(), $payload['actor_ids'] ?? [], true));
$run = fn () => match ($operation) {
    'batch-revoke' => app(ManageEntitlements::class)->revokeUnclaimedBatch(Batch::findOrFail($payload['batch_id']), User::findOrFail($payload['actor_id']), 'Chargeback'),
    'global-login' => app(ClientSessions::class)->login(Product::findOrFail($payload['product_ids'][$worker - 1]), User::findOrFail($payload['user_id'])->email, 'password', ['value' => 'pc-'.$worker])['token'],
    'device-reset' => (function () use ($payload): int {
        $user = User::findOrFail($payload['user_id']);
        app(DeviceBindings::class)->reset($user, Product::findOrFail($payload['reset_product_id']), $user);

        return 1;
    })(),
    'management-issue' => app(ManagementOperations::class)->create($payload['token'], $payload['plan_id'], 2, 'issue')->id,
    'management-revoke' => (function () use ($payload): int {
        app(ManageApiClients::class)->revoke(ApiClient::findOrFail($payload['client_id']), User::findOrFail($payload['actor_id']));

        return $payload['client_id'];
    })(),
    'hwid-upgrade' => (function () use ($payload, $worker): int {
        app(LicensedDevices::class)->upgrade(User::findOrFail($payload['user_id']), Product::findOrFail($payload['product_id']), ['v' => $payload['upgrade_version'] ?? $worker + 1, 'id' => 'pc']);

        return 1;
    })(),
    'hwid-rebind' => (function () use ($payload, $worker): int {
        app(DeviceBindings::class)->bind(User::findOrFail($payload['user_id']), Product::findOrFail($payload['product_id']), ['value' => 'pc-'.$worker]);

        return 1;
    })(),
    'ban' => app(ManageLicensingBans::class)->create(User::findOrFail($payload['user_id']), Product::findOrFail($payload['product_id']), 'Abuse', User::findOrFail($payload['actor_id']))->id,
    'login' => app(ClientSessions::class)->login(Product::findOrFail($payload['product_id']), User::findOrFail($payload['user_id'])->email, 'password', $hwid)['token'],
    'heartbeat' => app(ClientSessions::class)->heartbeat(Product::findOrFail($payload['product_id']), $payload['token'], $hwid)['expires_at'],
    'reassign' => DB::transaction(function () use ($payload): int {
        $product = Product::findOrFail($payload['product_id']);
        $product->update([$payload['column'] => $payload['destination_id']]);

        return $product->id;
    }),
    'start' => app(ManageOutages::class)->start(Product::findOrFail($payload['product_id']), 'outage', 'Deployment', User::findOrFail($payload['actor_id']))->id,
    'stop' => app(ManageOutages::class)->stop(Outage::findOrFail($payload['outage_id']), true, User::findOrFail($payload['actor_id']))->id,
    'cancel' => (function () use ($payload): int {
        app(ManageOutages::class)->cancel(LicensingOperation::findOrFail($payload['operation_id']), User::findOrFail($payload['actor_id']));

        return $payload['operation_id'];
    })(),
    'apply' => (function () use ($payload): int {
        (new ApplyCompensation($payload['operation_id']))->handle(app(Audit::class));

        return $payload['operation_id'];
    })(),
    'credit' => app(ManageOutages::class)->addTime(Product::findOrFail($payload['product_id']), 3600, CarbonImmutable::parse($payload['at']), 'compensation', 'Deployment', User::findOrFail($payload['actor_id']))->id,
    'claim' => app(ClaimLicense::class)->claim(User::findOrFail($payload['user_id']), $payload['key'])->id,
    'competing-claim' => app(ClaimLicense::class)->claim(User::findOrFail($payload['user_ids'][$worker - 1]), $payload['key'])->id,
    'issue' => app(CreateBatch::class)->create(LicensePlan::findOrFail($payload['plan_id']), 2, 'order', 'test')->id,
    'create-conflict' => app(CreateBatch::class)->create(LicensePlan::findOrFail($payload['plan_id']), 1, 'order', 'test')->id,
    'create-plan-conflict' => app(CreateBatch::class)->create(LicensePlan::findOrFail($payload['plan_ids'][$worker - 1]), 1, 'same-reference', 'test')->id,
    'create-plan' => app(CreateBatch::class)->create(LicensePlan::findOrFail($payload['plan_ids'][$worker - 1]), 1, 'plan-'.$worker, 'test')->id,
    'create-distinct' => app(CreateBatch::class)->create(LicensePlan::findOrFail($payload['plan_id']), 1, 'order-'.$worker, 'test')->id,
};
$waitFor = function (string $path): void {
    $deadline = microtime(true) + 15;
    while (file_get_contents($path) !== 'ready') {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Ordered race worker did not reach licensing lock.');
        }
        usleep(10000);
    }
};
$measuredStart = microtime(true);
try {
    if (($payload['first_operation'] ?? null) === null) {
        $result = $run();
    } elseif ($operation === $payload['first_operation']) {
        $result = DB::transaction(function () use ($run, $payload, $waitFor) {
            if ($payload['first_operation'] === 'batch-revoke') {
                User::whereKey($payload['actor_id'])->sharedLock()->firstOrFail();
                Product::whereKey($payload['product_id'])->lockForUpdate()->firstOrFail();
            } elseif ($payload['first_operation'] === 'claim' && isset($payload['batch_id'])) {
                User::whereKey($payload['user_id'])->lockForUpdate()->firstOrFail();
                Product::whereKey($payload['product_id'])->lockForUpdate()->firstOrFail();
            } elseif (str_starts_with($payload['first_operation'], 'management-')) {
                $id = $payload['client_id'];
                ApiClient::whereKey($id)->lockForUpdate()->firstOrFail();
            } elseif (in_array($payload['first_operation'], ['apply', 'cancel'], true) && isset($payload['product_id'])) {
                Product::whereKey($payload['product_id'])->lockForUpdate()->firstOrFail();
            } elseif (isset($payload['user_id'])) {
                User::whereKey($payload['user_id'])->lockForUpdate()->firstOrFail();
            } elseif (isset($payload['product_id'])) {
                Product::whereKey($payload['product_id'])->lockForUpdate()->firstOrFail();
            }
            file_put_contents($payload['ready_path'], 'ready');
            $waitFor($payload['contender_path']);
            usleep(100000);

            return $run();
        });
        if ($payload['stale_snapshot'] ?? false) {
            file_put_contents($payload['ready_path'], 'committed');
        }
    } else {
        $waitFor($payload['ready_path']);
        file_put_contents($payload['contender_path'], 'ready');
        $result = $run();
    }
} catch (LicensingException $exception) {
    $result = $exception->error;
}
if ($payload['measure'] ?? false) {
    $result = ['value' => $result, 'seconds' => microtime(true) - $measuredStart];
}
echo json_encode(['result' => $result], JSON_THROW_ON_ERROR);
