<?php

namespace LicenseKit\Console;

use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use LicenseKit\Core\Http\Requests\LoaderRequest;
use LicenseKit\Core\Http\Requests\ManagementWriteRequest;

class GenerateOpenApi extends Command
{
    protected $signature = 'licensekit:openapi {--output= : Save JSON to this file instead of stdout}';

    protected $description = 'Generate OpenAPI from the enabled LicenseKit routes and request rules.';

    public function handle(Router $router): int
    {
        $paths = [];
        foreach ($router->getRoutes()->getRoutes() as $route) {
            $name = $route->getName() ?? '';
            if (! str_starts_with($name, 'licensekit.')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $client = str_starts_with($name, 'licensekit.client.');
                $operation = ['operationId' => str_replace(['.', '-'], '_', $name), 'responses' => [
                    '200' => ['description' => $client ? 'Signed response. Check access before running software.' : 'Successful response'],
                    '401' => ['description' => 'Invalid credentials or token'], '403' => ['description' => 'Access denied'],
                    '422' => ['description' => 'Invalid input'], '429' => ['description' => 'Rate limit or device cooldown'],
                ]];
                if ($client) {
                    $operation['responses']['200']['headers'] = ['X-LicenseKit-Signature' => ['description' => 'Base64 Ed25519 signature over exact UTF-8 body bytes',
                        'schema' => ['type' => 'string']]];
                    $operation['description'] = 'All responses include signed nonce and Unix server_time. Missing signing setup returns 503 without granting access.';
                } elseif (str_starts_with($name, 'licensekit.management.')) {
                    $operation['security'] = [['connectionToken' => []]];
                } else {
                    $operation['description'] = 'Host web session authentication. POST requests require host CSRF token.';
                }
                foreach ($route->parameterNames() as $parameter) {
                    $operation['parameters'][] = ['name' => $parameter, 'in' => 'path', 'required' => true,
                        'schema' => ['type' => in_array($parameter, ['license', 'batch'], true) ? 'integer' : 'string']];
                }
                if ($method === 'POST') {
                    $rules = $client ? LoaderRequest::rulesFor($route->getActionMethod())
                        : ($name === 'licensekit.management.batches.store' ? (new ManagementWriteRequest)->rules()
                            : ($name === 'licensekit.portal.redeem' ? ['key' => ['required', 'string', 'max:64']] : []));
                    $properties = [];
                    $required = [];
                    foreach ($rules as $field => $fieldRules) {
                        if (in_array('missing', $fieldRules, true)) {
                            continue;
                        }
                        $properties[$field] = ['type' => in_array('integer', $fieldRules, true) ? 'integer' : 'string'];
                        if (in_array('required', $fieldRules, true)) {
                            $required[] = $field;
                        }
                    }
                    if ($properties !== []) {
                        $operation['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => $properties, 'required' => $required]]]];
                    }
                }
                $paths['/'.$route->uri()][strtolower($method)] = $operation;
            }
        }
        $json = json_encode(['openapi' => '3.0.3', 'info' => ['title' => 'LicenseKit API', 'version' => '1.0.0'],
            'paths' => (object) $paths, 'components' => ['securitySchemes' => ['connectionToken' => ['type' => 'http', 'scheme' => 'bearer']]]],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $path = $this->option('output');
        if (is_string($path) && $path !== '') {
            if (file_put_contents($path, $json."\n") === false) {
                return self::FAILURE;
            }
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }
}
