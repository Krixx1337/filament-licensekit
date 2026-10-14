<?php

foreach (['Core', 'Filament', 'Outages', 'Downloads', 'Modules', 'Diagnostics'] as $suite) {
    $bootstrap = __DIR__.'/'.$suite.'/Pest.php';
    if (is_file($bootstrap)) {
        require $bootstrap;
    }
}
