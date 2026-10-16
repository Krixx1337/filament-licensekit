<?php

test('installer preserves existing host configuration on repeated execution', function () {
    $path = config_path('licensekit.php');
    $original = is_file($path) ? file_get_contents($path) : null;
    try {
        file_put_contents($path, "<?php\nreturn ['host_marker' => 'preserved'];\n");
        $this->artisan('licensekit:install', ['--no-interaction' => true])->assertSuccessful();
        $this->artisan('licensekit:install', ['--no-interaction' => true])->assertSuccessful();
        expect(file_get_contents($path))->toContain("'host_marker' => 'preserved'");
    } finally {
        if ($original === null) {
            unlink($path);
        } else {
            file_put_contents($path, $original);
        }
    }
});

test('installer publishes missing configuration without querying or migrating the database', function () {
    $path = config_path('licensekit.php');
    $original = is_file($path) ? file_get_contents($path) : null;
    if ($original !== null) {
        unlink($path);
    }
    $connection = DB::connection();
    $connection->enableQueryLog();
    $connection->flushQueryLog();
    try {
        $this->artisan('licensekit:install', ['--no-interaction' => true])->assertSuccessful();
        expect(file_get_contents($path))->toBe(file_get_contents(__DIR__.'/../../../config/licensekit.php'));
        expect($connection->getQueryLog())->toBe([]);
    } finally {
        $connection->disableQueryLog();
        if ($original === null) {
            unlink($path);
        } else {
            file_put_contents($path, $original);
        }
    }
});
