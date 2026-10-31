# Releasing Bedrock

Bedrock ships one Composer package (`frolaxhq/bedrock`) and one npm package
(`@frolax/bedrock`). PHP administration, access and frontend integration are part of
mainline Bedrock. There are no companion repositories to split or publish.

1. Update the npm version to match the intended release.
2. Run `php scripts/release-verify.php 1.0.0`, `composer test`, `pnpm frontend:build`,
   `pnpm frontend:check`, `pnpm frontend:test` and `composer test:consumer`.
3. Run the browser module suite with `pnpm frontend:e2e`. Verify the foundation
   browser suite against a fresh consumer as described in `docs/foundation.md`.
4. Dispatch the release workflow with `dry_run` enabled and inspect its results.
5. After explicit approval, dispatch with `dry_run` disabled. It tags the main
   Composer repository, publishes npm with provenance and creates the GitHub release.

Configure npm trusted publishing for this repository and workflow. Packagist tracks
the main repository tags. No companion-package release token is required.

Publication is not transactional across registries. Repair a failed publication;
never reuse an already published version. Build and pack npm before the consumer
check when testing an unreleased version locally.
