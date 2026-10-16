<?php

foreach (['Core', 'Filament', 'Integration', 'Modules', 'Diagnostics'] as $suite) {
    $bootstrap = __DIR__.'/'.$suite.'/Pest.php';
    if (is_file($bootstrap)) {
        require $bootstrap;
    }
}
