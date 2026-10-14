# Publishing the first alpha

Package name: `licensekit/licensekit`.
Repository: https://github.com/Krixx1337/filament-licensekit.
Proposed first tag: `v0.1.0-alpha.1`.

The package has not been submitted to Packagist yet. These steps are for the maintainer when publishing is intended.

## Prepare the release

1. Commit the intended release changes and push them to GitHub.
2. Wait for every job in the `tests` workflow to pass, including the compatibility matrix, distribution archive check, and MySQL tests.
3. Change the changelog's `Unreleased` heading to `0.1.0-alpha.1` with the release date. Keep the pre-release warning.
4. Commit and push that changelog update, then wait for CI to pass on that commit.
5. Create and push the tag on that tested commit:

```sh
git tag -a v0.1.0-alpha.1 -m "LicenseKit 0.1.0-alpha.1"
git push origin v0.1.0-alpha.1
```

Composer reads the version from Git tags. Do not add a `version` field to `composer.json` or lower the package's `minimum-stability`.

## Submit to Packagist

1. Sign in to [Packagist](https://packagist.org) with the maintainer's GitHub account.
2. [Submit](https://packagist.org/packages/submit) the repository URL above. The package name comes from `composer.json`, not the repository name. Packagist must permit your account to use the `licensekit` vendor name.
3. Confirm `v0.1.0-alpha.1` appears on the package page and enable GitHub auto-updates. Follow [Packagist's GitHub hook instructions](https://packagist.org/about#how-to-update-packages) if automatic setup is unavailable.
4. Verify installation in a compatible Laravel application:

```sh
composer require licensekit/licensekit:0.1.0-alpha.1
php artisan licensekit:install --no-interaction
```

The exact alpha version allows this package's pre-release without allowing unstable dependencies throughout the application. Complete the panel, policy, user, and migration setup in [installation.md](installation.md).

Once the package is available, update the README and installation guide to use the Packagist command above and remove the VCS repository requirement. Keep the pre-release warning. Update the security policy to identify the supported alpha release.

Use a new tag for each subsequent release. Never move a published tag to different code.
