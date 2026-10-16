# Release checks

Package: `licensekit/licensekit`. Repository: [filament-licensekit](https://github.com/Krixx1337/filament-licensekit).

1. Run `composer check`, `php vendor/bin/pint --test`, `composer validate --strict` and `composer audit`.
2. When publishing is requested, push a review branch and wait for every CI job: Laravel/Filament compatibility matrix, lowest-dependency consumers, archive contents and MySQL redemption/issuance races.
3. Choose an unused release version. Add its date and changes to CHANGELOG.md. Keep pre-release warning and fresh-database requirement while this schema rewrite is unreleased.
4. Run CI on the exact release commit. Tag that tested commit only after release approval. Never move an existing published tag.
5. After pushing the approved tag, verify it appears on [Packagist](https://packagist.org/packages/licensekit/licensekit). If needed, check [webhook setup](https://packagist.org/about#how-to-update-packages).
6. Install that exact version in a supported host and complete [getting started](getting-started.md), including signed loader login, first redemption, heartbeat and device reset.

Composer reads version from Git tag. Do not add a version field to composer.json or relax minimum-stability for consumers.

Local SQLite results do not replace MySQL races. Local smoke checks do not replace lowest-dependency CI. Preserve both Filament majors and their minimum supported versions when editing matrix.
