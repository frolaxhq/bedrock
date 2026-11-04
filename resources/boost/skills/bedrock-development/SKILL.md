---
name: bedrock-development
description: >
  Configure and apply the Bedrock package in Laravel applications.
license: MIT
metadata:
  author: Bishwajit Adhikary
---

# Bedrock

Use this skill when a Laravel application needs to integrate Bedrock's module capability
to author, package, install, enable and serve backend or React/Inertia feature modules.

Bedrock scans a directory of self-contained module folders, records them in a registry
table, and boots the service provider of every enabled module. Modules are not composer
packages; each one is a folder with a `module.json` manifest.

## Workflow

### 1. Inspect the Laravel app context

- confirm the app is a Laravel 13 project on PHP 8.5 or newer
- check whether the `modules` table or a `module:*` command namespace is already in use
  before installing, since Bedrock registers both

Start from a fresh Laravel application and run `bedrock:install` to prepare auth,
admin and shared UI. Add business modules as needed. See `docs/foundation.md`.

### 2. Install and publish

```bash
composer require frolaxhq/bedrock
php artisan vendor:publish --tag="bedrock-config"
php artisan migrate
```

The `bedrock` tag publishes the same configuration file. The `modules` migration is loaded
from the package and is not publishable, so never copy it into the application's migrations
directory.

Bedrock includes PHP authentication, administration, access and frontend integration.
For a fresh Laravel application run `php artisan bedrock:install`; it prepares the User
model, migrations, frontend and first administrator. Use `--dry-run` to inspect changes,
`--check` for readiness, and `--skip-frontend --skip-admin` to defer those explicit steps.
Auth routes and `/admin` are enabled by the installer; registration stays disabled.
Installer preflight rejects conflicting route paths/names, incompatible guards and
package metadata/toolchain conflicts before writing files. Resolve those conflicts
or integrate manually; do not overwrite application customizations.
Configure mail for reset/verification. Read `docs/foundation.md` for existing-app integration.
The host owns its User model and business code. `@frolax/bedrock` supplies React UI/tooling.

### 3. Configure

Set the module root when the default does not fit. A relative path resolves against the
application root; an absolute path is used as is.

```php
// config/bedrock.php
'core' => [
    // Set this only when a dev checkout needs a semantic version identity.
    'version' => null,
],
'frontend' => [
    'enabled' => true,
    'client' => ['path' => 'public/vendor/bedrock/modules', 'url' => '/vendor/bedrock/modules'],
    'server' => ['path' => 'storage/framework/bedrock/frontend'],
    'development' => ['enabled' => false, 'path' => 'storage/framework/bedrock/frontend-dev'],
    'panels' => ['user_attribute' => 'type', 'fallback' => 'default'],
    'cache' => ['store' => null, 'key' => 'bedrock.frontend.registry', 'ttl' => 3600],
],
'modules' => [
    'path' => 'modules',
    'table' => 'modules',
    'manifest' => 'module.json',
    'bootstrap' => ['enabled' => true],
    'install' => [
        'max_archive_bytes' => 268435456,
        'max_entries' => 10000,
        'max_path_length' => 240,
    ],
    'locks' => ['store' => null, 'seconds' => 1500, 'wait' => 5],
    'cache' => [
        'enabled' => true,
        'store' => null,
        'key' => 'modules.registry',
        'ttl' => 3600,
    ],
],
```

Leave `modules.cache.enabled` on in production so boot does not query the registry on every
request. The registry degrades to an empty set when the table or the cache store is
missing, so an unmigrated app still boots.

`core.version` normally stays `null`, which makes Bedrock use the version Composer reports.
Set it explicitly when testing version-constrained modules against `dev-main`.

### 4. Register a module

New modules own `composer.json` and a committed `composer.lock`. Put private SDKs there;
keep host Laravel/Bedrock/Spatie requirements in `module.json.composer.require`. Run
`module:prepare {key}` then test the scoped runtime before `module:pack`. Use
`module:prepare {key} --force` to rebuild after source/lock changes; restart workers after
deploying rebuilt code. Installation still rejects stale supplied artifacts. Packaging
includes the isolated PHP runtime by default and omits raw vendor. `--without-php-runtime` packages
locked sources instead; installation prepares/downloads locked dependencies when needed.
`module:install --offline` forbids downloads; `--queue` schedules a dedicated background
installation. Missing/stale locks and unsupported scoping must be repaired by the author.
Never expose private SDK classes across module boundaries or modify host Composer files
to resolve a private SDK. Shared host integrations still require explicit host installation.
Use `module:recover {operation?}` to expire abandoned installation operations without
replaying migrations. Eligibility uses `modules.operations.stale_after` (3600 seconds by
default), bounded below by the configured subprocess and lock windows plus 60 seconds.
Stop surviving child processes and inspect partial migration/seeder effects before an
explicit retry. Schedule recovery explicitly if unattended cleanup is needed.

For upgrades, disable enabled dependents and the target, drain jobs, deploy the complete
new module directory at its existing path, then run `module:upgrade {key} --offline` in a
fresh Artisan process. The version must increase and all registered dependent constraints
must accept it. Required modules must already be installed and healthy. Upgrades preserve
inactive lifecycle state, run pending migrations without installation seeders, publish
artifacts, sync permissions, and emit `ModuleUpgraded`. The gateway exposes
`Module::upgrade($key)` for fresh processes. Inspect partial database/artifact changes on
failure; the command does not roll them back. Rebuild caches, reload workers/SSR, then
explicitly re-enable the module and its dependents. ZIP replacement and downgrades are not
part of this command.

Admin installs require an asynchronous `bedrock-install` worker and show operation status;
`sync` queues are rejected. Failed migrations require explicit repair/retry. Ready-built
runtime ZIPs need neither Composer nor internet. Old Composer-less modules remain supported.

Create `modules/<key>/module.json`. `key`, `name`, `version` and `provider` are required;
`key` must match the directory name and is lowercase letters, digits and dashes.

```json
{
    "key": "billing",
    "name": "Billing",
    "version": "1.0.0",
    "provider": "Modules\\Billing\\BillingServiceProvider",
    "requires": { "contacts": "^1.0" },
    "autoload": {
        "psr-4": { "Modules\\Billing\\": "src/" },
        "files": ["src/helpers.php"]
    },
    "composer": {
        "require": {
            "php": "^8.5",
            "laravel/framework": "^13.0",
            "ext-json": "*"
        }
    },
    "permissions": ["view", "manage"],
    "database": {
        "migrations": true,
        "seeders": true,
        "seeder": "Modules\\Billing\\Database\\Seeders\\BillingSeeder"
    },
    "frontend": {
        "driver": "inertia-react",
        "pages": "resources/js/pages",
        "artifact": "dist/bedrock/frontend.json",
        "requires": {
            "react": "^19.0",
            "react-dom": "^19.0",
            "@inertiajs/react": "^3.0",
            "@frolax/bedrock": "^1.0"
        }
    }
}
```

Then discover and enable it:

```bash
php artisan module:discover
php artisan module:install billing
php artisan module:enable billing
```

### 5. Build the module provider

Extend the base provider and set the module key and path. Every conventional location is
loaded only when it exists: `routes/web.php`, `routes/api.php`, `database/migrations`,
`resources/views`, `lang`, and `config/<key>.php`.

```php
namespace Modules\Billing;

use Frolax\Bedrock\Providers\Module\ModuleServiceProvider;

class BillingServiceProvider extends ModuleServiceProvider
{
    protected string $moduleKey = 'billing';

    protected string $modulePath = __DIR__.'/..';
}
```

Views and translations are namespaced under the module key: `billing::index` and
`billing::messages.greeting`.

### 6. Ship database resources

Put migrations in `database/migrations` and seeders in `database/seeders`, then declare them
in the manifest. Any install runs them before the module is registered — both
`module:install {key}` for a directory already in the module root and the `--from` / `--zip`
forms.

```json
"database": {
    "migrations": true,
    "seeders": true,
    "seeder": "Modules\\Billing\\Database\\Seeders\\BillingSeeder"
}
```

Bedrock registers manifest `autoload.psr-4` mappings before database work and loads
`autoload.files` exactly once during trusted install or boot. No host Composer mapping is
needed. Declaring `seeders` without a `seeder` runs no seeder. A failed migration or seeder
keeps lifecycle `Discovered`, marks health `Failed`, and exits non-zero; copied files and the
registry record remain for an explicit retry after repair.
Migrations also load at runtime through `ModuleServiceProvider`, so an enabled module whose
migrations were never run still has them registered with the migrator.

### 7. Use the module gateway or facade

```php
use Frolax\Bedrock\Facades\Module;

Module::enabled();
Module::isEnabled('billing');
Module::enable('billing');
Module::diagnose('billing');
Module::path();
```

Inject `Frolax\Bedrock\Module` when constructor injection is preferable. Resolve the
lower-level `ModuleRegistry` or `ModuleManager` contracts only when replacing or working
with one part of the capability.

### 8. Operate the administration console

After setting `bedrock.administration.enabled` to
`true`, Bedrock provides a package-owned console at `/bedrock/admin` for authenticated users
with the configured `bedrock.admin` permission. Prepare Spatie's tables and `HasRoles` on
the configured user model, run `php artisan bedrock:admin-install`, and grant an existing
user access with `php artisan bedrock:admin-user email@example.test`. The console can
inspect module health, install a module already present in the module directory, enable
or disable modules, edit declared settings, manage role permissions, and assign user roles.
It needs no host Vite build. ZIP installs, module upgrades, and uninstall remain console
workflows; do not present them as browser operations. Secret setting values are never
shown in the UI, and the final administrator cannot be removed.

Optional module pages can register with
`Frolax\Bedrock\Services\Administration\AdminPageRegistry` during provider boot using
`register($key, $label, $routeName, $permission, $moduleKey)`. Check its binding first.
The registry filters links only; protect the route with host authentication,
`RequireAdministrationEnabled`, the administration permission, and the page permission.
Conventional module routes already guard module enablement.

The optional backend-only `audit-log` module is installed from a validated ZIP with
`module:install audit-log --zip=/path/to/audit-log-1.0.0.zip`, then `module:enable audit-log`.
It records lifecycle/health, setting-key changes, and successful Bedrock access-adapter operations after
commit; it does not observe application models or record secret values. Capture begins on
the next application boot after enablement. Data is retained on disable/uninstall, with no
automatic retention deletion. Grant `audit-log.view` to an administrator for its read-only
screen. For explicit application events, resolve `Modules\AuditLog\AuditRecorder` only if
its binding is available, then call `record('invoice.approved', 'invoice', (string) $id)`.
Allowlist custom metadata in `audit-log.metadata_keys`. This module also runs on core alone.
Direct Spatie API calls are not automatically observed.

### 9. Author and package a full-stack module

```bash
composer require frolaxhq/bedrock
php artisan module:make billing --frontend
cd modules/billing
vp install
vp check
vp build
cd ../..
php artisan module:pack billing --output=dist/modules
```

`module:make billing` defaults to backend-only; `--backend-only` remains supported.
`--frontend` uses the included frontend integration. Generation only creates
files; it does not sync, migrate, install, or enable anything. `module:pack` requires an
existing frontend package-manager lockfile, runs the declared frontend build without installing npm dependencies,
creates a deterministic ZIP, and validates that ZIP through the same hardened installer.
Use `module:validate` on a clean source or staged package, not a working directory with
pnpm `node_modules` symlinks.

Frontend modules ship both browser federation and native ESM SSR artifacts. The generated
artifact descriptor lists every runtime file and SHA-256 hash. Installation validates and
publishes browser files to `public/vendor/bedrock/modules/<key>/<version>` and SSR files to
`storage/framework/bedrock/frontend/<key>/<version>` before database work runs.

### 10. Integrate a React/Inertia host

The supported host is Laravel 13 with React 19, Inertia 3, TypeScript, Tailwind 4,
Node 26 LTS, and Vite+ 1.0. The foundation installer pins the npm Vitest override
to 5.0.1 for Vite+ 1.0.0 peer compatibility and rejects conflicting existing versions. Run `vp migrate` at the application root before integrating
an existing Vite host. On a recognized React starter-kit layout:

```bash
composer require frolaxhq/bedrock
php artisan frontend:install --check
php artisan frontend:install --dry-run
php artisan frontend:install
vp install
vp check
```

The command changes declarations and integration files only; it never runs a package
manager. It is transactional and idempotent, and refuses unfamiliar files. The installer
adds Bedrock's shared checks and host plugin to an existing Vite+ configuration. It uses
`mergeBedrockToolchain` to preserve the host's explicit overrides and a verified pnpm
Vite+ catalog. For a new
host, import `defineHostConfig` from `@frolax/bedrock/vite` and pass its Laravel plugin;
the factory adds React, Tailwind, federation, and Bedrock's checks. Keep
`laravel-vite-plugin` in the host's dev dependencies. Install UI libraries directly in
the host only when host source imports them; Bedrock supplies its own UI dependencies.
Generated modules import
`defineModuleConfig` from the same entry point and need only their module key. Use its `vite`
option for explicit Vite+ overrides and `plugins` for extra plugins. The host keeps
React, React DOM, Inertia, and Bedrock's React/module/UI/theme runtimes as shared singletons,
so module pages inherit the same context and overrides. Enabled frontend descriptors arrive
in the reserved `__bedrock` Inertia prop and module pages use `module:{key}/{page}`.
The `vite` dependency added to the host is an alias to Vite+ core for plugin peers, not a
second frontend toolchain; `vite.config.ts` remains Vite+'s configuration filename.

After module activation/removal in production, rebuild caches and reload workers.
Module middleware rejects disabled routes even in a cached route collection, but running
PHP processes cannot unload code. Drain queued module jobs before removing their classes.

Bedrock's shared UI is generated with the official shadcn CLI using Base UI `base-nova`,
Tabler icons, CSS-variable themes, and RTL output. Import package UI from
`@frolax/bedrock/ui` so conventional host overrides apply everywhere. Known files under
`resources/js/components`, `hooks`, and `layouts` are detected by exact
lowercase kebab-case path; unrelated files are ignored.

```bash
php artisan ui:override --list
php artisan ui:override button
php artisan ui:override layouts/app-layout
php artisan ui:override layouts/auth-layout
```

The command copies package source to the matching conventional path and never overwrites an
existing file. No manual registry update is required. Use `ThemeProvider` for light, dark,
system, and `direction="ltr|rtl"`; redefine semantic variables in host `app.css` to theme
host, Bedrock, and module UI together. During package development, `pnpm workbench:dev`
starts Laravel plus host and canonical-module Vite+ servers with Fast Refresh.

Use `AuthLayout` for guest authentication flows and `AppLayout` for every authenticated user
type. Bedrock reads the configured user attribute, shares the safe key as
`__bedrock.viewer.panel`, and falls back to `default`. Build panel chrome conventionally:

```text
resources/js/panels/default/shell.tsx
resources/js/panels/default/slots/sidebar.tsx
resources/js/panels/default/slots/sidebar-footer.tsx
resources/js/panels/admin/slots/sidebar-footer.tsx
```

Panel files default-export React components and require no registry. A type-specific panel
inherits missing regions from `default`; `shell.tsx` replaces the complete shell. Use
`PanelSlot`, `PanelSidebarTrigger`, and `usePanel()` when authoring advanced shells. Keep
authorization in Laravel gates and policies—a panel key controls presentation only.

Author host navigation in `resources/js/navigation.ts`; Bedrock's Vite+ plugin detects it
without registration. Author module navigation beside its page glob in the single module
entry using `defineBedrockModule`, `defineNavigation`, `navigationItem`, and
`navigationAction` from `@frolax/bedrock/module`. Definitions, item `resolve()` callbacks,
badges, visibility, children, and actions may be async and receive an `AbortSignal`.

Never put `frontend.navigation` in `module.json`. `bedrock-artifact` imports the built SSR
entry and calls only `describeNavigation()` to generate the static security envelope.
Consumer installs validate this envelope and hashes without executing JavaScript. Laravel
resolves named routes and filters panels and canonical permissions before the frontend;
runtime code may alter presentation but cannot broaden that envelope.

The package shell supplies `PanelBrand`, `PanelHeader`, `PanelBreadcrumbs`,
`PanelNavigation`, and `PanelUserMenu`. Host files at
`resources/js/components/panel-*.tsx` override those defaults globally. Precedence is the
active panel slot, inherited `default` panel slot, conventional component override, then
package default. The viewer prop contains only configured identity fields, panel, active
Bedrock permissions, and existing profile/logout URLs—not the user model.

```php
use Frolax\Bedrock\Facades\Frontend;

Frontend::all();
Frontend::isAvailable('billing');
Frontend::page('billing', 'Invoices/Index');
```

## Commands

| Command | Description |
| --- | --- |
| `module:discover` | Discover modules on disk and register the ones that are new. |
| `module:list` | List registered modules with version, status and path. |
| `module:sync` | Reconcile the registry with the modules found on disk. |
| `module:doctor {key?} {--json}` | Run operational checks with human or stable JSON output. |
| `module:make {key} {--frontend} {--backend-only}` | Generate backend-only by default; request frontend source using the included frontend integration. |
| `module:validate {source} {--json}` | Validate source and artifacts without executing module code. |
| `module:pack {key-or-path} {--output=}` | Build from installed dependencies and create a deterministic validated ZIP. |
| `module:install {key}` | Register a module directory that already exists. |
| `module:upgrade {key} {--offline}` | Upgrade deployed code for an inactive installed module; pending migrations only. |
| `module:recover {operation?}` | Expire abandoned installations without replaying migrations. |
| `module:uninstall {key}` | Remove verified module code and its registry row while retaining data and published resources. |
| `module:enable {key}` | Activate a registered module. |
| `module:disable {key}` | Deactivate a module without removing files or data. |
| `module:cache {--warm}` | Rebuild the registry; optionally fully verify and warm production loading descriptors. |
| `module:clear` | Clear the cached registry. |
| `frontend:install {--check} {--dry-run}` | Integrate a recognized Laravel React host without running npm. |
| `ui:override {target?} {--list}` | List or seed a conventional host frontend override. |

The package is composed from focused providers. `BedrockServiceProvider` is the package
entry point, `CoreServiceProvider` owns shared configuration, and `ModulesServiceProvider`
owns the module gateway, bindings, commands, migration and runtime boot process.
`FrontendServiceProvider` owns artifact management, the frontend registry/gateway, Inertia
sharing, and host integration.
`Providers\Module\ModuleServiceProvider` is the abstract base a module's provider extends; the
package never registers it.

`module:discover`, `module:sync` and `module:uninstall` exit non-zero when they report a
problem, so they are safe to use in deployment scripts.

## Lifecycle and events

```
Discovered → Installed → Enabled → Disabled
```

Listen for the lifecycle events to react to state changes:
`ModuleDiscovered`, `ModuleInstalled`, `ModuleUpgraded`, `ModuleEnabled`, `ModuleDisabled`,
`ModuleUninstalled`, `ModuleFailed`, `ModuleRecovered` and `ModuleBootFailed`. The last event
is reserved for runtime provider registration failures.

A module retains its lifecycle status while health becomes `Failed` when files, autoloading,
providers, compatibility, dependencies, cycles, database work, locks or runtime bootstrap
fail. Running `module:sync` clears health after repair without guessing a previous status.
Enabling requires an installed, healthy module and registered, enabled, healthy, compatible
dependencies. Disabling or uninstalling refuses to break registered dependents.

An install recursively handles dependencies that are already present in the configured
module directory, running each dependency before its dependents. Enabled providers boot in
the same order. Missing local dependencies do not block copying a module for repair, but
they do block enablement. A circular chain is rejected before that chain's database work
runs, and the error reports the complete cycle.

## Rules, References, and Templates

- Read `config/bedrock.php` and the published `modules` migration before changing module
  packaging in an app.
- Do not hand-edit the registry table; go through the commands so the cache is invalidated.

## Examples

- Register a module and activate it:

```bash
php artisan module:discover
php artisan module:install billing
php artisan module:enable billing
php artisan module:list
```

- Assert module behavior in a consuming app's test suite:

```php
expect(Module::isEnabled('billing'))->toBeTrue();
```

- Scaffold a module directory in a test with the shipped helper:

```php
use Frolax\Bedrock\Testing\Module\ModuleManifestFactory;

$path = ModuleManifestFactory::for('demo')
    ->with(['version' => '2.0.0'])
    ->create(base_path('modules'));
```

## Anti-patterns

- Do not install a module by editing the registry table directly; the cached enabled set
  will be stale.
- Do not expect `module.json` to allow arbitrary keys; unknown keys are ignored, and an
  invalid key, version or missing required field makes the whole module rejected.
- Do not expect a module's seeder to run by convention. `seeders: true` without a `seeder`
  class name deliberately runs nothing; name the seeder in the manifest.
- Do not add module namespaces to the host Composer file. Declare them in manifest
  `autoload.psr-4`; a missing or unsafe target marks module health failed.
- Do not use arbitrary constraint syntax. `core` and `requires` use Composer constraints,
  and an invalid constraint rejects the complete manifest.
- Do not confuse module dependencies with private PHP libraries. Required modules must
  already be available locally; private Composer libraries may be downloaded from the module
  lockfile when its runtime/vendor is absent. Marketplace delivery remains a later capability.
- Do not build frontend modules on the consumer server. Publishers ship validated client
  and SSR artifacts; production installs need neither Node nor a host rebuild.
- Do not author navigation in `module.json` or duplicate its security metadata manually.
  Define it in TypeScript and let `bedrock-artifact` generate the static descriptor.
- Do not expose secret settings in an Inertia prop, a diagnostic response, an exception, or a
  serialized public API result. Use `Settings::forInertia()` for safe UI payloads and only expose
  a configured flag for secrets.
- Do not remove a managed permission or an assigned Spatie grant merely because a module is
  uninstalled. Mark its Bedrock catalog entry inactive; the Gate pre-check prevents use until
  a later module install declares it again.
- Do not bundle React, Inertia, or Bedrock's shared frontend runtimes into a module. Bedrock's
  Vite+ helper declares them as host-provided singletons and validates manifest requirements
  before loading.
- Do not hand-edit recognized host entry files when `frontend:install` can compose
  them safely; if it refuses a layout, follow its manual guidance instead of forcing edits.
- Do not document package internals here; keep the skill focused on adoption in Laravel apps.


## Immutable production PHP validation

Full validation remains the default. Only for immutable production releases, configure
`bedrock.modules.php.validation.cache=true`, a unique non-empty `release` ID per deployment,
a trusted optional cache `store`, and `ttl` (default 300 seconds). Rebuild config caches and
reload workers. Never mutate files within a cached release; rotate the release ID whenever
code changes. Cache hits skip source/runtime inventory hashing until expiry; arbitrary
in-place edits are not immediately detected. Descriptor keys include manifest/Composer/runtime/autoload metadata and PHP/extension
versions. Verified absolute maps skip repeated private graph parsing and path resolution;
shared host requirements remain checked. Missing/unavailable cache falls back to full checks. Explicit
validation, prepare/install/upgrade and `module:doctor` remain strict. Run diagnostics and
`module:cache --warm` before serving traffic. Warming always fully validates; use the same
release ID, paths, PHP/extensions and cache store as serving workers. Keep caching disabled for mutable deployments.

Laravel's `artisan optimize` warms descriptors automatically when production validation
caching and a release ID are configured; otherwise it caches the registry only.
`optimize:clear` includes registry clearing. Keep source/runtime files immutable and rotate
the release ID on every deployment; warming is a full check even for existing receipts.

## Compiled production boot

Opt in with `bedrock.modules.boot.compiled=true`, production environment, validation
cache enabled, and a fresh validation release ID for every deployment/host dependency
change. Prepare runtimes then run `module:cache --warm`; Laravel optimize also warms.
Warming strictly validates and publishes generation-specific PHP boot artifacts.
Runtime requests check shared generation, PHP and required extension versions and
execute all providers, without module fingerprints or manifest hydration. Source
changes require a new release and rebuild. Trusted cache/artifact storage is required.
Lifecycle writes/health changes/registry clears invalidate artifacts; successful
manager operations attempt rebuild. Cache read outages or absent artifacts use strict
boot. Cache invalidation outages block lifecycle writes. Multi-node deployments need
a shared registry cache store/key and a local warm artifact on every node after changes.
Rollback with a new release ID; do not update the database directly or reuse artifact
filenames with OPcache timestamp validation disabled.

Compiled mode uses an incremental Composer module classmap loader alongside the
host loader; inspect all loaders when inspecting classmap metadata. It preserves
callback order, module/file order and host mapping overrides. Every provider still
registers and boots through Laravel. Conventional view/lang directory checks may be
cached during warming; custom filesystem implementations and changed paths fall
back to ordinary checks. Rewarm under a fresh release ID for updated boot artifacts.

Compiled provider resource lookups reuse the snapshot already accepted for the current
request. Environment/release/generation validation runs when accepting the snapshot,
rather than repeating configuration checks for every view/lang directory. Rejected
reads and lifecycle invalidation clear resource metadata; custom filesystem checks
and all provider hooks retain their normal behavior.

Compiled boot artifact format 3 stores classmaps in immutable namespace groups.
Requests activate each module’s groups in order without expanding every SDK class
into a new PHP array. Host collisions and later overrides retain their normal
behavior, and metadata tooling can still call the module loader’s `getClassMap()`.
Formats 1 and 2 remain readable; warm a fresh release to obtain grouped maps.
