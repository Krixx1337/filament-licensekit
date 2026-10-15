<?php

$archive = new ZipArchive;
if ($archive->open($argv[1]) !== true) {
    throw new RuntimeException('Cannot open package archive.');
}

foreach (['composer.json', 'README.md', 'LICENSE', 'SECURITY.md', 'src/LicenseKitServiceProvider.php', 'config/licensekit.php', 'src/Core/Actions/IssueLicenses.php', 'src/Core/Actions/ImportLicensing.php', 'src/Core/Contracts/KeyNormalizer.php', 'routes/management.php', 'database/migrations/core/2026_10_12_000001_create_licensekit_core_tables.php', 'database/migrations/outages/2026_10_12_000002_create_licensekit_outage_tables.php', 'database/factories/Core/ProductFactory.php'] as $file) {
    if ($archive->locateName($file) === false) {
        throw new RuntimeException('Package archive is missing '.$file);
    }
}

for ($index = 0; $index < $archive->numFiles; $index++) {
    $file = $archive->getNameIndex($index);
    if (preg_match('~^(composer\.lock$|\.env(?:\.|$)|\.github/|\.agents/|\.codex/|vendor/|tests/|docs/|workbench/|storage/|phpunit\.xml$|phpstan\.neon$|pint\.json$)~', $file)) {
        throw new RuntimeException('Unexpected development file in package archive: '.$file);
    }
}

$archive->close();
echo "Package archive contains runtime files only.\n";
