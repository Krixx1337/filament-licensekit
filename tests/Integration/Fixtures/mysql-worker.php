<?php

require __DIR__.'/../../../vendor/autoload.php';
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LicenseKit\Core\Actions\ClaimLicense;
use LicenseKit\Core\Actions\CreateBatch;
use LicenseKit\Core\Actions\IssueLicenses;
use LicenseKit\Core\Actions\ManageApiClients;
use LicenseKit\Core\Actions\ManageEntitlements;
use LicenseKit\Core\Actions\ManagementOperations;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\ApiClient;
use LicenseKit\Core\Models\Batch;
use LicenseKit\Core\Models\Entitlement;
use LicenseKit\Core\Models\LicensePlan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Tests\Core\Fixtures\ClientSessions;
use LicenseKit\Tests\Integration\Fixtures\User;
use LicenseKit\Tests\Integration\MySqlTestCase;

$case = new MySqlTestCase('worker');
$case->createApplication();
$payload = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
$case->travelTo(CarbonImmutable::parse($payload['at']));
config(['host_client.free_until' => $payload['free_until'] ?? null]);
if ($payload['read_committed'] ?? false) {
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
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
$hwid = 'pc';
Gate::define('licensekit.admin', fn ($actor): bool => $actor->getKey() === ($payload['actor_id'] ?? null) || in_array($actor->getKey(), $payload['actor_ids'] ?? [], true));
$run = fn () => match ($operation) {
    'individual-pause', 'individual-extend' => (function () use ($operation, $payload): int {
        $entitlement = Entitlement::findOrFail($payload['entitlement_id']);
        $actor = User::findOrFail($payload['actor_id']);
        if ($operation === 'individual-pause') {
            app(ManageEntitlements::class)->pause($entitlement, $actor, 'Vacation');
        } else {
            app(ManageEntitlements::class)->extend($entitlement, 3600, $actor, 'Support');
        }

        return $entitlement->id;
    })(),
    'batch-revoke' => app(ManageEntitlements::class)->revokeUnclaimedBatch(Batch::findOrFail($payload['batch_id']), User::findOrFail($payload['actor_id']), 'Chargeback'),
    'local-issue', 'local-issue-stale' => (function () use ($payload): array {
        config(['licensekit.local_integrations.store' => ['enabled' => true, 'all_products' => true, 'maximum_quantity' => 2, 'disclose_serials' => true]]);

        $result = app(IssueLicenses::class)->issue('store', $payload['plan_id'], 2, 'purchase', discloseSerials: true);

        return ['batch_id' => $result->batchId, 'key_ids' => $result->keyIds, 'serials' => $result->serials, 'replayed' => $result->replayed];
    })(),
    'management-issue' => app(ManagementOperations::class)->create($payload['token'], $payload['plan_id'], 2, 'issue')->id,
    'management-revoke' => (function () use ($payload): int {
        app(ManageApiClients::class)->revoke(ApiClient::findOrFail($payload['client_id']), User::findOrFail($payload['actor_id']));

        return $payload['client_id'];
    })(),
    'login' => app(ClientSessions::class)->login(Product::findOrFail($payload['product_id']), User::findOrFail($payload['user_id'])->email, 'password', $hwid)['token'],
    'heartbeat' => app(ClientSessions::class)->heartbeat(Product::findOrFail($payload['product_id']), $payload['token'], $hwid)['expires_at'],
    'reassign' => DB::transaction(function () use ($payload): int {
        $product = Product::findOrFail($payload['product_id']);
        $product->update([$payload['column'] => $payload['destination_id']]);

        return $product->id;
    }),
    'claim' => app(ClaimLicense::class)->claim(User::findOrFail($payload['user_id']), $payload['key'])->id,
    'competing-claim' => app(ClaimLicense::class)->claim(User::findOrFail($payload['user_ids'][$worker - 1]), $payload['key'])->id,
    'issue' => app(CreateBatch::class)->create(LicensePlan::findOrFail($payload['plan_id']), 2, 'order', 'test')->id,
    'create-conflict' => app(CreateBatch::class)->create(LicensePlan::findOrFail($payload['plan_id']), 1, 'order', 'test')->id,
    'create-plan-conflict' => app(CreateBatch::class)->create(LicensePlan::findOrFail($payload['plan_ids'][$worker - 1]), 1, 'same-reference', 'test')->id,
    'create-plan' => app(CreateBatch::class)->create(LicensePlan::findOrFail($payload['plan_ids'][$worker - 1]), 1, 'plan-'.$worker, 'test')->id,
    'create-distinct' => app(CreateBatch::class)->create(LicensePlan::findOrFail($payload['plan_id']), 1, 'order-'.$worker, 'test')->id,
};
$waitFor = function (string $path, string $expected = 'ready'): void {
    $deadline = microtime(true) + 15;
    while (file_get_contents($path) !== $expected) {
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
            if (isset($payload['entitlement_id'])) {
                $entitlement = Entitlement::findOrFail($payload['entitlement_id']);
                User::whereKey([$payload['actor_id'], $entitlement->user_id])->orderBy('id')->lockForUpdate()->get();
                Product::whereKey($entitlement->product_id)->lockForUpdate()->firstOrFail();
            } elseif ($payload['first_operation'] === 'batch-revoke') {
                User::whereKey($payload['actor_id'])->sharedLock()->firstOrFail();
                Product::whereKey($payload['product_id'])->lockForUpdate()->firstOrFail();
            } elseif ($payload['first_operation'] === 'claim' && isset($payload['batch_id'])) {
                User::whereKey($payload['user_id'])->lockForUpdate()->firstOrFail();
                Product::whereKey($payload['product_id'])->lockForUpdate()->firstOrFail();
            } elseif (str_starts_with($payload['first_operation'], 'management-')) {
                $id = $payload['client_id'];
                ApiClient::whereKey($id)->lockForUpdate()->firstOrFail();
                if ($payload['first_operation'] === 'management-issue' && isset($payload['user_id'])) {
                    User::whereKey($payload['user_id'])->sharedLock()->firstOrFail();
                }
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
        if ($operation === 'local-issue-stale') {
            $result = DB::transaction(function () use ($run, $payload, $waitFor) {
                LicensePlan::findOrFail($payload['plan_id']);
                file_put_contents($payload['contender_path'], 'ready');
                $waitFor($payload['ready_path'], 'committed');

                return $run();
            });
        } else {
            file_put_contents($payload['contender_path'], 'ready');
            $result = $run();
        }
    }
} catch (LicensingException $exception) {
    $result = $exception->error;
}
if ($payload['measure'] ?? false) {
    $result = ['value' => $result, 'seconds' => microtime(true) - $measuredStart];
}
echo json_encode(['result' => $result], JSON_THROW_ON_ERROR);
