# Publishing releases

Package name: `licensekit/licensekit`.
Repository: https://github.com/Krixx1337/filament-licensekit.
First published tag: `v0.1.0-alpha.1`.

The package is published on [Packagist](https://packagist.org/packages/licensekit/licensekit). For the next alpha, use a new version such as `0.1.0-alpha.2`. The steps below use that version as an example.

## Prepare the release

1. Commit the intended release changes and push them to GitHub.
2. Wait for every job in the `tests` workflow to pass, including the compatibility matrix, distribution archive check, minimum-runtime issuance/import consumer, fresh-schema rollback/reinstall coverage, and MySQL claim/issuance/import tests.
3. Add a changelog entry for `0.1.0-alpha.2` with the release date and changes since the previous alpha. Keep the pre-release warning.
4. Commit and push that changelog update, then wait for CI to pass on that commit.
5. Create and push the tag on that tested commit:

```sh
git tag -a v0.1.0-alpha.2 -m "LicenseKit 0.1.0-alpha.2"
git push origin v0.1.0-alpha.2
```

Composer reads the version from Git tags. Do not add a `version` field to `composer.json` or lower the package's `minimum-stability`.

## Verify Packagist

1. Confirm `v0.1.0-alpha.2` appears on the package page after pushing the tag. The GitHub push webhook updates Packagist automatically. Follow [Packagist's GitHub hook instructions](https://packagist.org/about#how-to-update-packages) if updates stop working.
2. Verify installation in a compatible Laravel application:

```sh
composer require licensekit/licensekit:0.1.0-alpha.2
php artisan licensekit:install --no-interaction
```

The exact alpha version allows this package's pre-release without allowing unstable dependencies throughout the application. Complete the panel, policy, user, and migration setup in [installation.md](installation.md).

Update the README and installation guide to name the current published alpha. Keep the pre-release warning. The security policy supports the latest alpha and `main`.

Use a new tag for each subsequent release. Never move a published tag to different code.
