<div align="center">
    <h1>Bedrock</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/frolaxhq/bedrock"><img src="https://img.shields.io/packagist/v/frolaxhq/bedrock.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/frolaxhq/bedrock"><img src="https://img.shields.io/packagist/php-v/frolaxhq/bedrock.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/frolaxhq/bedrock"><img src="https://badge.laravel.cloud/badge/frolaxhq/bedrock?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/frolaxhq/bedrock/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/frolaxhq/bedrock/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/frolaxhq/bedrock"><img src="https://img.shields.io/packagist/dt/frolaxhq/bedrock.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Bedrock is a Laravel application foundation with authentication, a React/Inertia
admin shell, shared UI and an installable module system. Install it once, then build
your project's business features in the host application or reusable modules.

## Requirements

- PHP 8.5 or newer
- Laravel 13
- Node 26 LTS (`^26.13.0`) only when integrating the host frontend or authoring modules
- Vite+ 1.0 for frontend development; backend-only hosts need no Node runtime in production
- Node 26 LTS in production only when the host enables Inertia SSR

## Installation

Start with a fresh Laravel 13 application and configured database:

```bash
composer require frolaxhq/bedrock
php artisan bedrock:install
```

The installer prepares authentication, the existing User model, permission migrations,
React/Inertia assets and `/admin`. It runs migrations and the frontend build, then
prompts to create an administrator. Node 26.13+ and npm are needed for the build.
Configure mail delivery for password resets and email verification.

```bash
php artisan bedrock:install --dry-run
php artisan bedrock:install --check
php artisan bedrock:admin-create
npm run bedrock:dev
```

`--skip-frontend` and `--skip-admin` prepare the application while reporting the steps
still required. Non-interactive installation defers administrator creation. To grant
an existing account access, use `bedrock:admin-user {email-or-id}`.

All PHP capabilities ship in `frolaxhq/bedrock`. The npm `@frolax/bedrock` package supplies
shared React UI and independent module build/runtime tooling. The existing Blade
management console remains at `/bedrock/admin`; the new primary dashboard is `/admin`.

Public registration is disabled by default. Authentication and the new dashboard are
enabled by the installer, so merely requiring the package does not claim host auth routes.
Media, CMS, tenancy and 2FA are outside this release's foundation scope.

See [foundation setup and customization](docs/foundation.md) for routes, configuration,
manual integration, project pages, overrides and verification. Existing applications
must integrate explicitly; the installer refuses conflicting routes and generated files.
The package loads its module migrations directly: do not publish duplicate copies.

## Configuration

`config/bedrock.php` controls core compatibility, module storage, registry caching, and
managed frontend artifacts.

| Key                                 | Default                                  | Description                                                                                                                                                   |
|-------------------------------------|------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `core.version`                      | `null`                                   | Bedrock version used for module compatibility. `null` reads the installed version from Composer; set an explicit semantic version for a development checkout. |
| `modules.path`                      | `modules`                                | Directory scanned for modules. A relative path resolves against the application root; an absolute path is used as is.                                         |
| `modules.table`                     | `modules`                                | Database table used by the module registry.                                                                                                                   |
| `modules.manifest`                  | `module.json`                            | The manifest file every module directory must provide.                                                                                                        |
| `modules.bootstrap.enabled`         | `true`                                   | Boot the provider of every enabled and healthy module. Dynamic PHP autoloading itself is always available.                                                    |
| `modules.php.composer` | `["composer"]` | Composer executable and optional arguments used for isolated module preparation. |
| `modules.php.timeout` | `300` | Timeout in seconds for PHP tooling processes. |
| `modules.php.offline` | `false` | Forbid dependency and tooling downloads. |
| `modules.php.toolchain_path` | `storage/framework/bedrock/module-tools` | Isolated PHP build toolchain directory. |
| `modules.php.repositories` | `[]` | Host-controlled Composer repositories used for isolated PHP preparation. |
| `modules.operations.connection` | `null` | Asynchronous installation connection; defaults to the application's queue connection. |
| `modules.operations.queue` | `bedrock-install` | Dedicated installation queue. |
| `modules.operations.timeout` | `1200` | Maximum installation subprocess duration in seconds. |
| `modules.operations.stale_after` | `3600` | Minimum age before explicit recovery expires an operation. Never shorter than the execution or lock window plus 60 seconds. |
| `modules.cache.enabled`             | `true`                                   | Cache the enabled module set so boot does not query the database on every request.                                                                            |
| `modules.cache.store`               | `null`                                   | Cache store used for the registry snapshot. `null` uses the default store.                                                                                    |
| `modules.cache.key`                 | `modules.registry`                       | Cache key used for the registry snapshot.                                                                                                                     |
| `modules.cache.ttl`                 | `3600`                                   | Cache lifetime in seconds.                                                                                                                                    |
| `modules.install.max_archive_bytes` | `268435456`                              | Largest total size a module archive may expand to when installed with `--zip`.                                                                                |
| `modules.install.max_entries`       | `10000`                                  | Maximum files and directories accepted from a module archive or local source.                                                                                 |
| `modules.install.max_path_length`   | `240`                                    | Maximum relative path length inside a module package.                                                                                                         |
| `modules.locks.store`               | `null`                                   | Cache store used for atomic lifecycle locks. `null` uses the default store.                                                                                   |
| `modules.locks.seconds`             | `1500`                                   | Lifetime of each lifecycle lock; keep above the installation timeout.                                                                                         |
| `modules.locks.wait`                | `5`                                      | Seconds lifecycle operations wait for a contended lock.                                                                                                       |
| `frontend.enabled`                  | `true`                                   | Share enabled frontend module descriptors through Inertia. Backend-only modules are unaffected.                                                               |
| `frontend.client.path`              | `public/vendor/bedrock/modules`          | Versioned browser artifacts managed by Bedrock.                                                                                                               |
| `frontend.client.url`               | `/vendor/bedrock/modules`                | Public URL prefix corresponding to the managed browser path.                                                                                                  |
| `frontend.server.path`              | `storage/framework/bedrock/frontend`     | Protected native ESM artifacts used by the SSR process.                                                                                                       |
| `frontend.development.enabled`      | `false`                                  | Allow short-lived local development descriptors in the local environment.                                                                                     |
| `frontend.development.path`         | `storage/framework/bedrock/frontend-dev` | Directory holding local development descriptors.                                                                                                              |
| `frontend.panels.user_attribute`    | `type`                                   | Authenticated user attribute used as the conventional panel key. Dot notation is supported.                                                                   |
| `frontend.panels.fallback`          | `default`                                | Safe panel key used when the user attribute is missing or invalid.                                                                                            |
| `frontend.viewer.attributes.*`      | `id`/`name`/`email`                      | Host user attributes projected into the narrow frontend viewer payload; avatar is disabled until configured.                                                  |
| `frontend.viewer.actions.*`         | profile/logout route names               | Existing named routes exposed as safe relative action URLs; missing routes are omitted.                                                                        |
| `frontend.cache.*`                  | store/key/TTL                            | Cache settings for the enabled frontend descriptor registry.                                                                                                  |
| `settings.table`                    | `module_settings`                        | Table containing values retained for each module setting.                                                                                                     |
| `settings.cache.*`                  | store/key/TTL                            | Cache settings for resolved module values.                                                                                                                    |
| `permissions.catalog_table`         | `module_permissions`                     | Bedrock's module-permission catalog; it preserves ownership and active state independently of grants.                                                         |
| `permissions.guard`                 | `web`                                    | The single Spatie permission guard administered by Bedrock.                                                                                                   |
| `administration.enabled`            | `false`                                  | Opt in to the authenticated Blade console when `bedrock-admin` is installed. |

The registry degrades to an empty set when the `modules` table or the cache backing store
does not exist yet, so an unmigrated application still boots.

The package registers a configurable `modules` table and the fixed `module:*` command
namespace. Set `modules.table` before migrating when a host application already owns the
default table name.

## Module manifest

Every module provides a `module.json` file. Only the identity fields are required.

```json
{
    "schema": 1,
    "key": "billing",
    "name": "Billing",
    "version": "1.0.0",
    "description": "Invoicing and payments.",
    "provider": "Modules\\Billing\\BillingServiceProvider",
    "core": "^1.0",
    "requires": {
        "contacts": "^1.0"
    },
    "autoload": {
        "psr-4": {
            "Modules\\Billing\\": "src/"
        },
        "files": ["src/helpers.php"]
    },
    "composer": {
        "require": {
            "php": "^8.5",
            "laravel/framework": "^13.0",
            "ext-json": "*",
            "vendor/package": "^2.0"
        }
    },
    "permissions": ["view", "manage"],
    "settings": {
        "currency": "USD"
    },
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
    },
    "assets": {}
}
```

| Field              | Required | Notes                                                                                                                                                                |
|--------------------|----------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `key`              | Yes      | Lowercase letters, digits and dashes, starting with a letter. Must match the module directory name.                                                                  |
| `name`             | Yes      | Display name.                                                                                                                                                        |
| `version`          | Yes      | Semantic version, for example `1.2.0`.                                                                                                                               |
| `provider`         | Yes      | Fully qualified service provider class.                                                                                                                              |
| `schema`           | No       | Manifest schema version. Defaults to `1`.                                                                                                                            |
| `description`      | No       | Human readable summary.                                                                                                                                              |
| `core`             | No       | Composer constraint required of the installed Bedrock version.                                                                                                       |
| `requires`         | No       | Map of module key to Composer version constraint.                                                                                                                    |
| `autoload`         | No       | Module-owned PSR-4 prefixes and files. Prefixes end in `\\`; paths must be safe, relative, present, and contained by the module.                                     |
| `composer.require` | No       | Host PHP, extension, Laravel, and installed package requirements. Bedrock validates these without running Composer.                                                  |
| `permissions`      | No       | Permission strings or structured `{key, label, description}` declarations. Bedrock canonicalizes them as `{module-key}.{permission-key}`.                            |
| `settings`         | No       | Scalar defaults or typed definitions (`text`, `textarea`, `number`, `boolean`, `select`, `multiselect`, `secret`).                                                   |
| `database`         | No       | Whether the module ships migrations and seeders, and which seeder to run.                                                                                            |
| `frontend`         | No       | An `inertia-react` frontend contract, source pages, prebuilt artifact descriptor, and shared npm constraints. Omit it for backend-only modules.                    |
| `assets`           | No       | Publishable asset metadata.                                                                                                                                          |

Unknown keys are ignored, so a manifest written for a newer Bedrock release still loads.
Invalid Composer constraints reject the manifest during discovery. An incomplete local
installation retains copied files and a failed registry record for repair, does not run
migrations or seeders, and exits non-zero. Bedrock never mutates the host Composer installation. Private PHP runtime preparation
may download locked dependencies and tooling unless `--offline` is enabled.

When an install targets a module whose dependencies are also present in the configured
module directory, Bedrock installs that complete local dependency chain first. The same
dependency-first order is used when enabled providers boot. A dependency that is not
available locally does not prevent the files from being installed for repair, but it still
prevents enablement and produces a failed state during synchronization. Circular chains are
rejected before migrations or seeders in that chain run, and the error lists the complete
cycle, for example `billing -> payments -> billing`.

## Commands

| Command                                       | Description                                                                                                                                                               |
|-----------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `module:discover`                             | Discover modules on disk and register the ones that are new.                                                                                                              |
| `module:list`                                 | List every registered module with its version, status and path.                                                                                                           |
| `module:sync`                                 | Reconcile the registry with the modules found on disk.                                                                                                                    |
| `module:doctor {key?} {--json}`               | Diagnose storage, locks, manifests, autoloading, providers, drift, compatibility, dependencies, cycles, and recorded health failures.                                     |
| `module:make {key} {--frontend} {--backend-only}` | Generate a backend module by default; request frontend files with the included frontend integration. Creates files only. |
| `module:validate {source} {--json}`           | Validate source, manifest, autoloading, and built frontend artifacts without executing module code.                                                                       |
| `module:prepare {key-or-path} {--offline} {--force}` | Prepare locked, isolated private PHP dependencies; explicitly rebuild authored changes with `--force`. |
| `module:pack {key-or-path} {--output=} {--without-php-runtime}` | Build frontend artifacts when present and package a validated ZIP, including the prepared PHP runtime by default. |
| `module:install {key}`                        | Install a module directory and its locally available dependencies.                                                                                                        |
| `module:install {key} --from=<dir>`           | Copy a module into the module root, then install it and its locally available dependencies.                                                                               |
| `module:install {key} --zip=<archive>`        | Extract a module into the module root, then install it and its locally available dependencies.                                                                            |
| `module:uninstall {key}`                      | Disable when needed, safely remove module code and the registry row, and retain database data and published resources.                                                    |
| `module:enable {key}`                         | Activate a registered module.                                                                                                                                             |
| `module:disable {key}`                        | Deactivate a module without removing its files or data.                                                                                                                   |
| `module:recover {operation?}` | Mark expired queued or running installation operations failed; releases their active key without rerunning migrations. |
| `module:upgrade {key} {--offline}` | Upgrade an inactive installed module from code already deployed in its directory; runs pending migrations without installation seeders. |
| `module:cache {--warm}` | Rebuild the registry; optionally fully verify and warm immutable production runtime descriptors. |
| `module:clear`                                | Clear the cached registry of enabled modules.                                                                                                                             |
| `frontend:install {--check} {--dry-run}`      | Idempotently integrate a recognized Laravel 13 React starter-kit host without running a package manager.                                                                  |
| `ui:override {target?} {--list}`              | List or seed a conventional host frontend override without overwriting files.                                                                                             |
| `bedrock:admin-install {--check} {--dry-run}` | Inspect the local administration prerequisites and prepare Spatie's publishable configuration and migrations without migrating, modifying the user model, or running npm. |
| `bedrock:admin-user {identifier}`             | Grant the protected `bedrock-admin` role to an existing user identified by primary key or email.                                                                          |
| `bedrock:permissions-sync {module?}`          | Reconcile the core permission catalog; the included access integration also synchronizes Spatie permissions. |

A typical first run:

```bash
php artisan module:discover
php artisan module:install billing
php artisan module:enable billing
```

To install a module that is not in the module root yet, point `--from` at a directory
holding the module:

```bash
php artisan module:install billing --from=/path/to/billing
```

The source directory is copied, not moved, so it can be a checkout or a build output that
stays where it is. The key you pass must match the `key` in the manifest, and the install is
refused when the module root already holds that key — run `module:uninstall {key}`
first when you intend to replace it.

A module can also be distributed as a ZIP archive:

```bash
php artisan module:install billing --zip=/path/to/billing.zip
```

The archive must hold the module either at its root or inside a single top level directory,
which is how a repository is normally zipped. Every entry is inspected before anything is
extracted: entries that point outside the archive, symbolic links and other non regular
entries reject the whole archive, and each extracted file is compared against its recorded
checksum so a corrupt download is refused rather than installed. Extraction happens in a
temporary directory outside the module root, which is removed whether the install succeeds
or fails. Use either `--from` or `--zip`, not both. The total size an archive may expand to is
capped by `install.max_archive_bytes`, which defaults to 256 MB.

## Typed settings and permissions

Settings can stay concise for simple values or declare their presentation and validation
contract. Persisted values override manifest defaults and remain in place when a module is
disabled or uninstalled. `secret` values are encrypted at rest; they are never included in
the Inertia payload, diagnostic output, or serialized public gateway results. Use the settings
gateway to replace or explicitly reset a secret value.

```json
{
    "settings": {
        "currency": {
            "type": "select",
            "label": "Currency",
            "default": "USD",
            "rules": ["required", "string"],
            "options": {"USD": "US Dollar", "BDT": "Bangladeshi Taka"}
        },
        "api_key": {
            "type": "secret",
            "label": "API key",
            "default": null,
            "rules": ["nullable", "string"]
        }
    },
    "permissions": ["view", {"key": "manage", "label": "Manage billing"}]
}
```

Use the capability gateways directly in application code. Settings and permissions belong
to core; navigation ships in the main package:

```php
use Frolax\Bedrock\Facades\Navigation;
use Frolax\Bedrock\Facades\Permissions;
use Frolax\Bedrock\Facades\Settings;

Settings::set('billing', 'currency', 'BDT');
Permissions::can($user, 'billing', 'manage');
Navigation::all($user);
```

The included access integration uses `spatie/laravel-permission` for
one configured guard. The permission catalog uses Laravel Gate for authorization, and
applications can provide their own gates. `bedrock:install` prepares the User integration
and permission migrations for fresh applications. For manual integration, add `HasRoles`
to the configured user model, prepare migrations, then grant an existing user access:

```bash
php artisan bedrock:admin-install
php artisan migrate
php artisan bedrock:admin-user admin@example.test
```

Set `bedrock.administration.enabled` to `true` in the published configuration. The
package-owned administration console is available at `/bedrock/admin` after an
administrator signs in. It requires the configured `bedrock.permissions.guard` and
`bedrock.permissions.admin_permission` (by default `web` and `bedrock.admin`). Its
server-rendered screens work without a host frontend build. Operators can inspect
registered modules and their health checks, install a module already present in the
configured module directory, enable or disable it, edit declared module settings,
assign active Bedrock permissions to roles, and assign roles to users from the
configured auth provider. Secret settings are never rendered back into form values.
The protected administrator role cannot lose administration access, and the last
administrator cannot be removed. Browser installation from a ZIP, upgrading a module,
and uninstalling it remain Artisan-only workflows for now; the console does not accept
arbitrary filesystem paths or uploads.

Modules can add optional administration pages during their provider's `boot()`:

```php
use Frolax\Bedrock\Services\Administration\AdminPageRegistry;

if ($this->app->bound(AdminPageRegistry::class) && config('bedrock.administration.enabled')) {
    $this->app->make(AdminPageRegistry::class)->register(
        'reports', 'Reports', 'reports.index', 'reports.view', 'reports',
    );
}
```

The registry filters links by permission and module state; it does not authorize the
destination. Protect the route with the host's authentication guard, administration
enablement middleware (`RequireAdministrationEnabled`), `can:bedrock.admin`, and the
page's own permission. Conventional module routes also enforce module enablement.

## Authoring and packaging modules

Generate a backend module by default, or request frontend files with the included frontend tooling:

```bash
php artisan module:make billing --frontend
php artisan module:make reports
php artisan module:make notifications --backend-only
```

The generator only writes files. It never syncs, migrates, installs, or enables the module.
A frontend module owns its package manager lockfile and build dependencies; Bedrock never
installs npm packages or accesses the network on its behalf.

```bash
cd modules/billing
vp install
vp check
vp build
cd ../..

php artisan module:pack billing --output=dist/modules
```

For frontend builds, `module:pack` detects npm, pnpm, Yarn, or Bun from `packageManager`/the lockfile, runs only
the existing `build` script, excludes VCS data, `node_modules`, temporary output and
`.bedrockignore` matches, normalizes archive metadata, and validates the completed ZIP
through the installer. It does not install frontend dependencies. Run `module:validate` on a clean
source or staged package; a working directory containing pnpm's `node_modules` symlinks is
intentionally rejected by the raw-source validator.

### Private PHP dependencies

New modules contain their own `composer.json` and `composer.lock`. Add private SDKs in the
module directory during authoring, commit the lockfile, and test the prepared runtime:

```bash
cd modules/reports
composer require vendor/sdk:^1.0
cd ../..
php artisan module:prepare reports
php artisan module:pack reports
```

After changing prepared PHP sources or the module lockfile, rebuild explicitly with
`php artisan module:prepare reports --force`. Installation rejects supplied stale/corrupt
runtimes rather than silently rebuilding them. Rebuild during authoring/deployment and
reload application workers afterward.

`composer.json.require` describes module-private dependencies. Keep Laravel, Bedrock and
host integrations such as Spatie in `module.json.composer.require`; these are validated
against the host and are never installed into a module's private vendor directory. If a
host integration is missing, install the indicated compatible package in the host and retry.

Preparation scopes private libraries and their references to a module-specific namespace.
Module public namespaces stay stable. Do not expose private SDK objects in APIs shared with
the host or other modules; use module-owned DTOs, shared contracts or ordinary data. Dynamic
class construction, generated code and unusual autoloaders need author-provided scoped
runtime tests; arbitrary Composer packages are not guaranteed to support isolation.

Default ZIPs include the generated `dist/php` runtime and omit raw `vendor`. A ready-built
ZIP is verified and installs without Composer or internet. A ZIP with a lockfile but no
runtime automatically prepares it during installation, reusing verified bundled vendor
when present or downloading the locked production dependencies when absent. Install never
performs `composer update`, runs Composer scripts/plugins, or edits the host Composer files.

```bash
php artisan module:pack reports --without-php-runtime
php artisan module:install reports --zip=reports-1.0.0.zip
php artisan module:install reports --zip=reports-1.0.0.zip --offline
php artisan module:install reports --zip=reports-1.0.0.zip --queue
```

`--offline` forbids dependency/tool downloads. Missing or stale lockfiles, vendor inventory
mismatches and invalid runtime hashes fail explicitly. Hashes check consistency, not the
identity or trustworthiness of a publisher: modules execute trusted PHP application code.
The installer never runs a module-supplied PHP-Scoper config. Scoping tools run in their own
locked Composer environment, separately from the host. Source preparation requires a
configured Composer executable; ready-built runtime installation does not.

Admin installation is queued on `bedrock-install`. Configure a real asynchronous queue
connection (`sync` is rejected) and a dedicated worker. Each operation runs a fresh Artisan
subprocess so installed module code never enters the long-running queue worker. The admin
screen shows operation state and redacted errors. Installation does not enable the module.
Database failures require explicit repair/retry rather than automatic job retries.

```bash
php artisan queue:work --queue=bedrock-install --timeout=1260 --tries=1
```

Set the connection's `retry_after` above the worker timeout, and keep the module lock lifetime
above the installation timeout. After activation, rebuild Laravel deployment caches and
reload application workers and SSR processes. Uninstall removes module-owned code and
dependencies while retaining existing application/module database records. Legacy modules
without their own Composer manifest retain the previous installation behavior.

### Production PHP validation cache

Full source/runtime SHA256 inventory verification is the default. For immutable production
releases, opt in through `config/bedrock.php`:

```php
'modules' => [
    'php' => [
        'validation' => [
            'cache' => true,
            'release' => env('APP_RELEASE'),
            'store' => null,
            'ttl' => 300,
        ],
    ],
],
```

Set `APP_RELEASE` to a unique deployment ID and rebuild Laravel's config cache on each
deployment. Source and prepared runtime files must remain immutable for that release;
changing files in place without changing the release ID is unsupported. Leave caching
disabled for mutable installations. A cache hit deliberately does not detect arbitrary
source/runtime edits until expiry; this optimization is not a tamper-detection guarantee.

Only runtime loading uses these receipts, and only in `production` with a non-empty release
ID. The first load performs full verification. Receipts are isolated by application path,
module/runtime real paths, PHP version, release ID, and runtime metadata. Metadata changes
and TTL expiry trigger full checks. Cache keys fingerprint the module manifest, Composer
metadata, runtime metadata, autoload
maps, PHP version and extension versions. A verified descriptor reuses resolved absolute
autoload paths and skips private dependency inspection and per-file path resolution. Shared
host requirements are still checked during module boot. Cache outages fall back to full
verification. Use a trusted cache store; its write access is part of the deployment trust
boundary. Keep the TTL short and never edit module files while a release is serving traffic.

Explicit validation, preparation, installation, upgrades, and `module:doctor` retain full
verification. Run `module:doctor` during deployment, then run `php artisan module:cache --warm` before routing traffic to it. Laravel’s `php artisan optimize` automatically includes this
warm step when immutable production validation caching is configured; otherwise it only
caches the registry. `optimize:clear` includes registry clearing. This command
always performs full verification, including when a descriptor is already cached. Warm with
the same paths, release ID, cache store and PHP/extensions as the serving workers; otherwise
the first HTTP request will safely build its own descriptor. Reload workers and SSR
processes when deploying code.

### Recover interrupted installations

Run `php artisan module:recover` after a worker crash, lost queue message, or interrupted
dispatch. Pass an installation UUID to limit recovery to one operation. Schedule this
command with Laravel's scheduler if unattended expiration is desired; Bedrock does not
register a schedule automatically.

Only active records older than `modules.operations.stale_after` are eligible. Age is
measured from `started_at` for claimed work and `created_at` for undelivered work, and the
effective limit is at least the configured subprocess timeout and lock lifetime plus 60
seconds. Keep these limits above actual worker and process durations. Recent operations
and terminal records are unchanged. Late queue delivery cannot restart a recovered record.

Recovery marks the operation failed and releases its active key. It never dispatches a
retry, rolls back schema changes, or reruns migrations. Before explicitly installing again,
stop any surviving installation subprocess, inspect migration and seeder effects, repair
partial database changes, and run `module:doctor`. A timed-out or killed migration may have
made changes without recording completion in Laravel's migrations table.

### Upgrade an installed module

Upgrades use a deployment-managed directory replacement. They do not download releases,
replace code from ZIPs, downgrade versions, or automatically roll back database changes.

1. Back up the database and module code. Disable enabled dependents first, then disable
   the target module. Drain module jobs and stop long-running processes that use its code.
2. Deploy the new complete module directory at the same configured path. Include prepared
   private PHP runtime and frontend artifacts when required. Keep a copy of the old release.
3. In a fresh Artisan process, run:

   ```bash
   php artisan module:upgrade billing --offline
   php artisan module:doctor billing
   ```

4. Rebuild deployment caches, reload application workers and SSR processes, and explicitly
   enable the upgraded module followed by its dependents.

The new manifest must have a strictly newer version. Required modules must already be
installed, healthy, and compatible; every registered dependent must accept the new version.
Cycles and active installation operations reject the upgrade. The command preserves the
installed/disabled lifecycle state, validates and publishes frontend artifacts, runs only
pending migrations, updates the registry snapshot, synchronizes the permission catalog,
and emits `ModuleUpgraded` with the previous version. Installation seeders are not replayed;
put upgrade data changes in new migrations.

On validation or migration failure, the registered version remains unchanged and execution
failures record unhealthy status. Files, published artifacts, and database effects are not
automatically reverted. Inspect and repair them before explicitly retrying the upgrade.
The PHP gateway/facade also exposes `Module::upgrade('billing')`; invoke it only in a fresh
process with the target inactive, because PHP cannot unload previously loaded classes.

## Optional audit history

The first-party backend-only `audit-log` module is shipped as source under
`modules/audit-log`. Package it with `module:pack` in a development host, then install its
validated ZIP in a consumer:

```bash
php artisan module:install audit-log --zip=/path/to/audit-log-1.0.0.zip
php artisan module:enable audit-log
```

It records module lifecycle/health events, changed setting keys, and successful access
operations through Bedrock's Spatie adapter. Direct Spatie calls are not observed.
Capture starts when its enabled provider boots on a subsequent application
boot; it does not reconstruct past events. No model observers or raw requests are captured.
Records are appended after transaction commit, skipped on rollback, and retained when the
module is disabled or uninstalled. Storage failures are logged without reversing completed
operations. There is no automatic retention deletion, and the module is not a tamper-proof
compliance ledger.

For explicit application events, call the recorder only while the module is available:

```php
use Modules\AuditLog\AuditRecorder;

if (app()->bound(AuditRecorder::class)) {
    app(AuditRecorder::class)->record('invoice.approved', 'invoice', (string) $invoice->getKey());
}
```

Custom metadata must be allowlisted in `audit-log.metadata_keys`; credentials, request
payloads, and exception objects are excluded. Authenticated actors are stored by identifier,
supporting numeric IDs and UUIDs. With administration installed and enabled, grant
`audit-log.view` to an authorized administrator to access `/bedrock/admin/audit-log`.
Core-only hosts can record events without installing administration, Spatie, Inertia, or Node.

Module state changes are not PHP code unloading. The conventional route middleware blocks
disabled modules even with cached routes, but activation/removal still requires rebuilding
configuration/routes and reloading long-running workers during deployment. Drain queued
work before removing its module code. No schema rollback or business-data deletion is
performed by disable/uninstall.

## Frontend host integration

Bedrock supports the Laravel 13 React stack: React 19, Inertia 3, TypeScript, Tailwind 4,
and Vite+ 1.0. Use Node 26 LTS. In an existing host, run the [Vite+ migration](https://viteplus.dev/guide/migrate)
at the application root first. Then let the safe installer compose the host Inertia
resolver and Vite+ configuration:

```bash
composer require frolaxhq/bedrock
php artisan frontend:install --check
php artisan frontend:install --dry-run
php artisan frontend:install
vp install
vp check
```

Core rejects modules declaring frontend features when their handler package is absent;
backend modules need neither Inertia nor Node. Node and Vite+ are required only for
frontend development and builds.

The command recognizes the Laravel React starter-kit layout, writes a dedicated
`resources/js/bedrock-pages.ts`, and refuses unfamiliar/conflicting entry files rather than
overwriting them. It requires a Vite+ config, adds Bedrock's shared checks and host plugin,
and never invokes a package manager. Existing hosts keep their plugins and application-specific
settings, including a verified Vite+ pnpm catalog; the installer merges nested checks with
host overrides via `mergeBedrockToolchain`. New hosts can compose those defaults through `defineHostConfig` from
`@frolax/bedrock/vite`, for example:

```ts
import { defineHostConfig } from '@frolax/bedrock/vite';
import laravel from 'laravel-vite-plugin';

export default defineHostConfig({
    plugins: [laravel({ input: ['resources/css/app.css', 'resources/js/app.tsx'], ssr: 'resources/js/ssr.tsx' })],
    lint: {
        rules: { 'no-console': 'off' },
    },
});
```

Bedrock owns the React, Tailwind, and federation Vite plugins used by these factories.
The host keeps `laravel-vite-plugin` as an application-specific dependency, plus packages
its own source imports and the Vite+ CLI/core alias. Install additional UI libraries in
the host only when host code imports them directly; Bedrock's UI dependencies come with
`@frolax/bedrock`.

`defineModuleConfig({ key: 'billing' })` supplies the standard module entries, React and
Tailwind plugins, federation, Oxfmt, Oxlint, and type-aware checks. Pass `vite` for explicit
Vite+ overrides and `plugins` for additional module plugins. Run `vp check` for all three
checks, `vp test` for frontend tests, and `vp build` for client or SSR builds. Existing built
module ZIPs remain installable without an authoring-toolchain change. Vite+ still reads
`vite.config.ts`. The `vite` dependency in frontend manifests is
an alias to Vite+ core, needed by Laravel, React, Tailwind, and federation plugins that
declare a `vite` peer; it is not a separate Vite toolchain.

### Shared UI, themes, and conventional overrides

Bedrock ships its initial component catalog from the official shadcn CLI using the Base UI
`base-nova` style, Tabler icons, CSS-variable theming, and RTL output. Enabled buttons and
`role="button"` controls use a pointer cursor by default; disabled controls keep their
not-allowed behavior. Import shared UI from `@frolax/bedrock/ui` so the same host override is
used by host pages, Bedrock pages, and installed frontend modules.

Host overrides are detected by exact conventional path. No registry or Bedrock-specific
override directory is required:

```text
resources/js/components/ui/button.tsx
resources/js/components/panel-navigation.tsx
resources/js/hooks/use-theme.ts
resources/js/layouts/app-layout.tsx
resources/js/layouts/auth-layout.tsx
```

Seed an override from the package-owned source without overwriting an existing host file:

```bash
php artisan ui:override --list
php artisan ui:override button
php artisan ui:override components/panel-navigation
php artisan ui:override layouts/app-layout
php artisan ui:override layouts/auth-layout
```

Use `AuthLayout` for login, registration, password recovery, verification, and other guest
flows. `AppLayout` is the single authenticated entry point and selects a panel from the
authenticated user's configured attribute (`type` by default). Bedrock shares only the safe
panel key as `__bedrock.viewer.panel`; policies and permissions remain responsible for
authorization.

Panels are composed from host files without a registry:

```text
resources/js/panels/
├── default/
│   ├── shell.tsx
│   └── slots/
│       ├── header.tsx
│       ├── sidebar-header.tsx
│       ├── sidebar.tsx
│       ├── sidebar-footer.tsx
│       └── footer.tsx
└── admin/
    ├── shell.tsx
    └── slots/
        └── sidebar-footer.tsx
```

Every file default-exports a React component and uses lowercase kebab-case. A selected panel
inherits missing slots and its shell from `default`; an unknown user type also resolves to
`default`. Override `shell.tsx` for complete control, or use `PanelSlot` inside a custom shell
for additional named regions. The package shell includes an accessible responsive sidebar,
mobile drawer, RTL-safe placement, browser-local collapse preference, and `usePanel()` state.
The default panel convention applies to host, Bedrock, and federated module pages without
rebuilding a module.

`ThemeProvider` supports `light`, `dark`, and `system`, plus `direction="ltr|rtl"`. A host can
redefine semantic variables in `resources/css/app.css`; those values flow through package,
host, and module UI without rebuilding a module. The workbench demonstrates component,
hook, panel-region, and token overrides. Run all local HMR servers with:

```bash
pnpm workbench:dev
```

The command starts Laravel, the host Vite+ server, and the canonical module Vite+ server.
Editing a known component, layout, hook, panel shell, or panel slot receives React Fast
Refresh; adding or removing a known conventional file triggers a controlled reload.

Module authors build browser federation and SSR artifacts before distribution. At install,
Bedrock verifies every SHA-256 checksum before migrations, publishes client files under the
versioned public path, and keeps the native ESM SSR entry under protected storage. The host
is built once: installing or enabling a later module does not rebuild it and production
does not need Node. React, React DOM, Inertia, and Bedrock's React/module/UI/theme runtimes
remain host-provided singletons, so a federated page sees the same context and overrides as
the host shell.

Enabled, healthy descriptors are shared in the reserved Inertia prop `__bedrock`. Compose
the resolver with the host's local page glob; module page names use
`module:{key}/{page}`. Module CSS is loaded once per URL, remote loads are cached by
key/version, and failed transient loads can be retried.

### TypeScript navigation and application chrome

Navigation is executable frontend behavior, so it belongs in TypeScript rather than
`module.json`. A module's single federated `./module` entry exports pages, a static security
description, async presentation resolvers, and actions:

```tsx
import { IconCreditCard, IconRefresh } from '@tabler/icons-react';
import {
    defineBedrockModule,
    defineNavigation,
    navigationAction,
    navigationItem,
} from '@frolax/bedrock/module';

const frontend = defineBedrockModule({
    pages: import.meta.glob('./pages/**/*.tsx'),
    navigation: defineNavigation({
        groups: [{ key: 'commerce', label: 'Commerce', order: 20 }],
        items: [
            navigationItem({
                key: 'billing',
                label: 'Billing',
                route: 'billing.index',
                group: 'commerce',
                icon: IconCreditCard,
                permission: 'view',
                panels: ['admin'],
                active: ['billing.*'],
                async resolve({ signal, page, can }) {
                    return {
                        visible: page.props.billingEnabled === true && can('view'),
                        badge: await fetchUnreadCount({ signal }),
                    };
                },
            }),
            navigationAction({
                key: 'billing-sync',
                label: 'Sync billing',
                icon: IconRefresh,
                permission: 'manage',
                async run({ signal, notify }) {
                    await synchronizeBilling({ signal });
                    notify.success('Billing synchronized.');
                },
            }),
        ],
    }),
});

export const {
    resolvePage,
    describeNavigation,
    materializeNavigation,
    resolveNavigation,
    runNavigationAction,
} = frontend;
```

The publisher-side artifact command calls only `describeNavigation()` and records its
function-free route, permission, panel, hierarchy, group, and active-pattern envelope.
Consumer installation validates that descriptor and its hashes without executing module
JavaScript. Laravel resolves named routes, canonicalizes module permissions, and removes
unauthorized panel entries before the browser or SSR runtime sees them. Runtime resolvers
may change presentation such as labels, icons, badges, visibility, and disabled state, but
cannot add an authorized item or change its protected route or permission.

Host navigation is automatically discovered at `resources/js/navigation.ts` and uses the
same `defineNavigation()` contract. Definitions and presentation resolvers may be sync or
async. Pending work receives an `AbortSignal`, defaults to a two-second fail-soft timeout,
and can be made strict through `createBedrockResolver({ navigation: { strict: true } })`.
SSR stores only the serializable result in the current Inertia page; hydration reuses it
without repeating requests, then later Inertia visits resolve fresh state.

The package's default shell renders `PanelBrand`, `PanelHeader`, `PanelBreadcrumbs`,
`PanelNavigation`, and `PanelUserMenu`. Override any of them conventionally:

```text
resources/js/components/panel-brand.tsx
resources/js/components/panel-header.tsx
resources/js/components/panel-navigation.tsx
resources/js/components/panel-user-menu.tsx
```

Panel slots still have higher precedence, followed by the inherited `default` panel slot,
then these global component overrides, then package defaults. `__bedrock.viewer` is a narrow
projection containing identity, panel, active Bedrock permissions, and only existing profile
and logout action URLs; the complete user model is never shared. Logout uses an Inertia POST.

### Module database resources

When a module declares database resources, an install runs them before the module is
registered. Both `module:install {key}` and the `--from` / `--zip` forms do this, so a
module is never registered with an unmigrated database.

```json
"database": {
    "migrations": true,
    "seeders": true,
    "seeder": "Modules\\Billing\\Database\\Seeders\\BillingSeeder"
}
```

- `migrations` — run every migration in the module's `database/migrations` directory.
- `seeders` — whether the module seeds itself at all.
- `seeder` — the fully qualified seeder class. Bedrock registers the module's declared
  PSR-4 mappings before migrations and seeders, so no host `composer.json` edit is needed.

Migrations run through `migrate --path` and seeders through `db:seed --class`; both are
called with `--force`, because an install is not an interactive prompt. A module that sets
`seeders` to `true` without naming a `seeder` runs no seeder: a class name is the only handle
`db:seed` can resolve, and guessing one would run whatever else shares the name.

Migrations of an enabled module are also loaded at runtime by `ModuleServiceProvider`, so a
module whose migrations have not run yet still has them registered with the migrator.

When a migration or seeder fails, the lifecycle remains `Discovered`, health becomes
`Failed`, `ModuleFailed` carries a stable failure code and reason, and the command exits
non-zero. The copied files stay in place for an explicit retry after repair.

## Lifecycle

A module moves through the following states.

```
Discovered → Installed → Enabled → Disabled
```

- **Discovered** — the module directory and manifest were found and registered.
- **Installed** — the module is registered and healthy but not active.
- **Enabled** — the module provider is booted at runtime; routes, views, translations and config are available.
- **Disabled** — the module stays on disk with its data intact, but nothing is booted.
- **Health** — every lifecycle state independently has `Healthy` or `Failed` health. A
  failure records `failure_code`, `failure_reason`, and `failed_at` without losing the
  operator's intended lifecycle state. Enabled-but-failed modules do not boot.

`module:sync` authoritatively refreshes same-version snapshots, detects missing or invalid
files, version drift, unsafe autoloading, provider failures, incompatible requirements,
disabled dependencies, and cycles. A successful later sync clears health without guessing
or changing lifecycle status. Disabling or uninstalling a module is refused while it would
break registered dependents; destructive operations never cascade.

## Module structure

A module directory is a miniature Laravel package. Extend
`Frolax\Bedrock\Providers\Module\ModuleServiceProvider` and set the module key and path;
every conventional location is loaded only when it exists.

```
modules/billing/
├── module.json
├── src/
│   ├── BillingServiceProvider.php
│   └── helpers.php
├── routes/
│   ├── web.php
│   └── api.php
├── database/
│   ├── migrations/
│   └── seeders/
├── resources/
│   ├── css/module.css
│   └── js/
│       ├── pages.ts
│       └── pages/Index.tsx
├── lang/
├── config/
│   └── billing.php
├── dist/bedrock/
│   ├── frontend.json
│   ├── client/
│   └── server/
├── package.json
├── vite.config.ts
└── public/
```

```php
namespace Modules\Billing;

use Frolax\Bedrock\Providers\Module\ModuleServiceProvider;

class BillingServiceProvider extends ModuleServiceProvider
{
    protected string $moduleKey = 'billing';

    protected string $modulePath = __DIR__.'/..';
}
```

The base provider wires routes from `routes/web.php` and `routes/api.php`, migrations from
`database/migrations`, views and translations under the module key namespace
(`billing::index`, `billing::messages.greeting`), configuration from `config/billing.php`,
and publish tags `modules` and `module-billing`.

## Module gateway and facade

```php
use Frolax\Bedrock\Facades\Module;

Module::enabled();
Module::isEnabled('billing');
Module::install('billing');
Module::enable('billing');
Module::diagnose('billing');
Module::uninstall('billing');
Module::path();
```

The `Frolax\Bedrock\Module` gateway can also be injected directly. The lower-level
`ModuleRegistry` and `ModuleManager` contracts remain available when an application needs
to replace or interact with one part of the capability.

Frontend discovery has its own gateway and facade:

```php
use Frolax\Bedrock\Facades\Frontend;

Frontend::all();
Frontend::find('billing');
Frontend::isAvailable('billing');
Frontend::page('billing', 'Invoices/Index'); // module:billing/Invoices/Index
```

## Package architecture

The package composes focused providers instead of one large one, so each concern can be
read and tested on its own:

| Provider | Responsibility |
| --- | --- |
| `Frolax\Bedrock\BedrockServiceProvider` | Package entry point. It only composes the shared core and shipped capability providers. |
| `Frolax\Bedrock\Providers\CoreServiceProvider` | Recursively merges `config/bedrock.php` and publishes the package configuration. |
| `Frolax\Bedrock\Providers\ModulesServiceProvider` | Owns the module gateway, bindings, commands, migration and enabled-module boot process. |
| `Frolax\Bedrock\Providers\FrontendServiceProvider` | Owns managed artifacts, the frontend registry/gateway, Inertia sharing, and host integration. |

`src/Providers` holds two kinds of class, and the directory layout keeps them apart:

- **Package wiring**, directly in `src/Providers` — capability providers composed by
  `BedrockServiceProvider`. Consumers never extend them.
- **The module extension point**, in `src/Providers/Module` — the abstract
  `ModuleServiceProvider` a module's own provider extends. The package never registers it.

Module authors extend `Frolax\Bedrock\Providers\Module\ModuleServiceProvider` and set the
module key and path.

Future Bedrock-owned capabilities follow the same boundary: a focused provider, gateway,
optional facade, configuration section and tests. An installable application feature such
as billing or payments remains a module and owns any feature-specific facade itself.

## Development

The repository ships one canonical full-stack module in `workbench/modules/example`.
The following commands exercise its independent build and backend lifecycle:

```bash
pnpm install --frozen-lockfile
pnpm frontend:build
pnpm exec vp check
pnpm exec vp -C workbench/modules/example build
composer build
vendor/bin/testbench module:discover
vendor/bin/testbench module:install example
vendor/bin/testbench module:enable example
vendor/bin/testbench module:doctor example
composer serve
```

`composer build` migrates the workbench database, so run it before the commands above.

For debugging, `spatie/laravel-ray` is installed as a development dependency, so `ray()`
is available while working on the package and disappears from a consumer install.

## Testing

```bash
composer test
```

This runs PHPStan, the Pint style check, type coverage and the Pest suite. Frontend checks
run with `pnpm frontend:check`, `pnpm frontend:test`, `pnpm frontend:build`, and
`pnpm frontend:e2e`; these scripts use Vite+. Individual
commands are available as `composer analyse`, `composer lint:check`, `composer test:types`
and `composer test:unit`.

`composer test:consumer` boots six fresh Laravel applications: core, access, admin,
frontend, audit, and the complete stack. It checks production dependencies and optional
resources in isolated temporary directories without changing the workbench database.

## Contributing

Thank you for considering contributing to Bedrock! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Bishwajit Adhikary](https://github.com/frolaxhq)
- [All Contributors](../../contributors)

## License

Bedrock is open-sourced software licensed under the [MIT license](LICENSE.md).

### Compiled production module boot

For immutable deployments, opt into `bedrock.modules.boot.compiled = true`, enable
`bedrock.modules.php.validation.cache`, and assign a unique validation `release`
for every deployment, including host Composer dependency changes. Run
`php artisan module:cache --warm` after deploying dependencies and preparing modules,
then build config/route caches. Laravel's `optimize` integration also warms modules.
Development and the default configuration retain dynamic validation.

Warming strictly validates all enabled runtimes, compatibility and dependency order
before publishing a generation-specific PHP artifact under `bootstrap/cache`.
Requests verify the shared generation, PHP version and required extension versions,
then register the compiled autoload maps and every module provider. Custom provider
register/boot hooks still execute. Module source edits within a release are not
checked on this path: rebuild under a new release ID. Cache and artifact directories
must be trusted and writable only by trusted application/deployment processes.

Registry writes invalidate the generation before persistence; registry refresh,
clear, and module health changes invalidate it too. Successful manager lifecycle
operations attempt a rebuild. An unavailable generation store prevents registry
writes; missing/stale artifacts and cache read outages fall back to strict boot.
A failed rebuild leaves strict boot active. Use `module:clear` to invalidate manually,
and `module:cache --warm` to rebuild. Direct database writes bypass lifecycle
invalidation and are unsupported. Existing requests may finish with their boot snapshot.

On multiple nodes, configure a shared `modules.cache.store` and the same registry key;
run warming on each node after the final lifecycle change. Each node needs its own
artifact at its local application path. Deploy/rollback with a fresh release ID and
warm again. Artifact filenames are never reused, supporting OPcache with timestamp
validation disabled. Remove obsolete artifacts only after their releases/workers are
retired; do not copy artifacts between different absolute application paths.

Compiled boot registers module classmaps incrementally on a dedicated Composer
loader immediately before the host loader, keeping existing callback order,
module registration order, autoload-file execution order and host classmap override
behavior. This avoids copying the entire host map for every enabled module.
Module classmaps are available on that loader; tools inspecting autoload metadata
should inspect all loaders rather than assuming the host loader contains every map.
Custom `ModuleAutoloader` implementations keep their normal bootstrap path.

Warming also records conventional view/language directory availability for providers
using the base filesystem implementation. Custom filesystem implementations and
paths changed by a custom constructor retain their normal checks. Provider register
and boot hooks continue to execute through Laravel; none are automatically deferred.
Existing compiled artifact formats remain readable; rebuild with a fresh deployment
release ID to use the latest format and optimizations.

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
