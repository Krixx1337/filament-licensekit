<?php

// Runs the Python example against a real default API.
// `php snippet-server.php setup` seeds a customer and writes client.py; `php -S 127.0.0.1:8000 snippet-server.php` serves the API.

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use LicenseKit\Core\Actions\ResponseSigning;
use LicenseKit\Core\Models\License;
use LicenseKit\Core\Models\Product;
use LicenseKit\LicenseKitServiceProvider;
use LicenseKit\Tests\Core\Fixtures\User;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\Foundation\Application;

require __DIR__.'/../../vendor/autoload.php';

$dir = getenv('SNIPPET_DIR') ?: sys_get_temp_dir().'/licensekit-snippets';
if (! is_dir($dir)) {
    mkdir($dir, 0777, true);
}

$app = Application::create(null, fn ($app) => $app->booting(fn () => $app['config']->set([
    'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
    'auth.providers.users.model' => User::class,
    'database.default' => 'snippets',
    'database.connections.snippets' => ['driver' => 'sqlite', 'database' => $dir.'/database.sqlite', 'prefix' => '', 'foreign_key_constraints' => true],
    'licensekit.signing_key_path' => $dir.'/signing.key',
    'cache.default' => 'file',
    'cache.stores.file.path' => $dir.'/cache',
])), ['extra' => ['providers' => [LivewireServiceProvider::class, LicenseKitServiceProvider::class]]]);

if (PHP_SAPI === 'cli-server') {
    $kernel = $app->make(Kernel::class);
    $response = $kernel->handle($request = Request::capture());
    $response->send();
    $kernel->terminate($request, $response);

    return;
}

touch($dir.'/database.sqlite');
$migrator = $app->make('migrator');
$migrator->getRepository()->createRepository();
$migrator->run([__DIR__.'/../../tests/Core/Fixtures/migrations', ...$migrator->paths()]);
app(ResponseSigning::class)->install();

$user = User::factory()->create(['email' => 'customer@example.test']);
$product = Product::factory()->create(['slug' => 'demo']);
License::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);

$base = 'http://127.0.0.1:8000/'.config('licensekit.client_prefix').'/demo';
$client = str_replace(['__BASE__', '__PUBLIC_KEY__'], [$base, app(ResponseSigning::class)->publicKey()],
    (string) file_get_contents(__DIR__.'/../../resources/snippets/client.py'));
file_put_contents($dir.'/client.py', $client.<<<'PY'

result = call("login", login="customer@example.test", password="password", device="ci-pc")
assert result["access"] is True, result
heartbeat = call("heartbeat", token=result["token"], device="ci-pc")
assert heartbeat["access"] is True, heartbeat
print("Python example: signed login and heartbeat OK")

PY);
echo $dir.'/client.py'.PHP_EOL;
