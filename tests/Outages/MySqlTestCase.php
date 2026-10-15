<?php

namespace LicenseKit\Tests\Outages;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\SanctumServiceProvider;
use LicenseKit\LicenseKitServiceProvider;
use LicenseKit\Tests\Core\Fixtures\ClientProtocolProvider;
use LicenseKit\Tests\Outages\Fixtures\User;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Process\Process;

class MySqlTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('LICENSEKIT_MYSQL_TESTS') !== '1') {
            $this->markTestSkipped('Set LICENSEKIT_MYSQL_TESTS=1 for dedicated MySQL package concurrency tests.');
        }
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    protected function getPackageProviders($app): array
    {
        return [SanctumServiceProvider::class, LicenseKitServiceProvider::class, ClientProtocolProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        if (getenv('DB_DATABASE') !== 'licensekit_package_test') {
            throw new \RuntimeException('Package concurrency tests require dedicated database licensekit_package_test.');
        }
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql', [
            'driver' => 'mysql', 'host' => getenv('DB_HOST') ?: '127.0.0.1', 'port' => getenv('DB_PORT') ?: '3306',
            'database' => 'licensekit_package_test', 'username' => getenv('DB_USERNAME'), 'password' => getenv('DB_PASSWORD'),
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ]);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('licensekit.user.suspended_column', 'is_suspended');
        $app['config']->set('licensekit.modules.outages', true);
        $app['config']->set('queue.default', 'null');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('cache.default', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->app['migrator']->path(__DIR__.'/Fixtures/migrations');
        $this->app['migrator']->path(__DIR__.'/../../vendor/laravel/sanctum/database/migrations');
    }

    /** Bootstrap entry point for isolated worker processes, not a test. */
    public function worker(): void {}

    /** @param list<string> $operations
     * @param  array<string,mixed>  $payload
     * @return list<mixed>
     */
    public function race(array $operations, array $payload, ?string $firstOperation = null): array
    {
        Schema::create('licensekit_test_barrier', fn (Blueprint $table) => $table->id());
        $readyPath = tempnam(sys_get_temp_dir(), 'licensekit-ban-ready-');
        $contenderPath = tempnam(sys_get_temp_dir(), 'licensekit-ban-contender-');
        $processes = [];
        foreach ($operations as $operation) {
            $process = new Process([PHP_BINARY, __DIR__.'/Fixtures/mysql-worker.php', $operation, json_encode([...$payload, 'at' => now()->toIso8601String(), 'first_operation' => $firstOperation, 'ready_path' => $readyPath, 'contender_path' => $contenderPath], JSON_THROW_ON_ERROR)]);
            $process->setTimeout(30);
            $process->start();
            $processes[] = $process;
        }
        try {
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                if (! $process->isSuccessful()) {
                    throw new \RuntimeException($process->getErrorOutput().$process->getOutput());
                }
                try {
                    $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR)['result'];
                } catch (\JsonException $exception) {
                    throw new \RuntimeException($process->getOutput().$process->getErrorOutput(), previous: $exception);
                }
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            Schema::dropIfExists('licensekit_test_barrier');
            unlink($readyPath);
            unlink($contenderPath);
        }
    }
}
