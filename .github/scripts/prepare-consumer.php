<?php

$directory = $argv[1];
$version = $argv[2];

if (! is_dir($directory)) {
    mkdir($directory, 0777, true);
}

file_put_contents($directory.'/composer.json', json_encode([
    'name' => 'licensekit/compatibility-consumer',
    'require' => [
        'php' => '^8.3',
        'laravel/framework' => $version,
        'licensekit/licensekit' => 'dev-main',
    ],
    'repositories' => [[
        'type' => 'path',
        'url' => str_replace('\\', '/', dirname(__DIR__, 2)),
        'options' => ['symlink' => false, 'versions' => ['licensekit/licensekit' => 'dev-main']],
    ]],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
