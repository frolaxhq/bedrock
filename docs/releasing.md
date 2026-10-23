# Releasing Bedrock

Bedrock is released as four Composer packages and one npm package:

| Package                          | Distribution repository          |
| -------------------------------- | -------------------------------- |
| `frolaxhq/bedrock`               | `frolaxhq/bedrock`               |
| `frolaxhq/bedrock-access-spatie` | `frolaxhq/bedrock-access-spatie` |
| `frolaxhq/bedrock-admin`         | `frolaxhq/bedrock-admin`         |
| `frolaxhq/bedrock-frontend`      | `frolaxhq/bedrock-frontend`      |
| `@frolax/bedrock`                | npm                              |

The companion repositories are generated from this monorepo. Do not edit their generated
contents directly; edit the matching directory under `packages/php` here.

## One-time setup

Before the first release, create the three companion repositories in the `frolaxhq`
organization, give the release environment a `FROLAXHQ_RELEASE_TOKEN` secret with access to
them, and register the four Composer repositories with Packagist. Configure npm trusted
publishing for `@frolax/bedrock` from this GitHub repository and its `release` workflow.

Create and publish `frolaxhq/bedrock-starter` as a separate repository too. It is a Laravel
project package, not a library. Its `composer.json` requires `frolaxhq/bedrock:^1.0`, has no
path repository, and must not commit a local-development `composer.lock`.

## Release process

1. Update `packages/frontend/package.json` so its version equals the intended release.
2. Run `php scripts/release-verify.php 1.0.0`, then run the normal PHP and frontend checks.
   Install the locked module tools with `composer install --working-dir=resources/module-tools
--no-dev --no-scripts --no-plugins` before the private dependency isolation tests. Release
   archives include that toolchain's manifest/lock/runner, but never its local `vendor`.
3. Dispatch the `release` workflow with `dry_run` enabled. It validates metadata, packages,
   and tests without changing a registry or repository.
4. Review the generated output and changelog. Dispatch it again with `dry_run` disabled only
   when the release is approved.

The publishing run splits the three Composer packages to their own repositories, pushes the
same version tag to each, tags core, publishes the npm package with provenance, and creates
the GitHub release. Packagist receives the Composer tags through its repository webhooks.

Publishing is not transactional across GitHub, Packagist, and npm. If a later step fails,
stop and repair the failed registry or repository; never reuse a published version.
