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

Bedrock is a capability-driven application foundation for Laravel. Each first-class
capability owns its provider, public gateway and optional facade, so the package can grow
without turning its root service provider or API into a collection of unrelated methods.

The module system is Bedrock's first capability. It lets an application install, enable,
disable and uninstall self-contained feature modules without touching the host
application's source tree.

Each module is a directory at the application root containing a `module.json` manifest, a
service provider, and any combination of routes, migrations, views, translations, config
and assets. Bedrock discovers those directories, records them in a database registry, and
boots only the providers of the modules that are enabled.

## Requirements

- PHP 8.5 or newer
- Laravel 13
- Node 20.19+ or 22.12+ only when integrating the host frontend or authoring modules

## Installation

You can install the package via Composer:

```bash
composer require frolaxhq/bedrock
```

Publish the configuration file and run the migrations:

```bash
php artisan vendor:publish --tag="bedrock-config"
php artisan migrate
```

The `modules` migration is loaded from the package and is deliberately not
publishable: copying it into the application while the package also loads it would
run the same migration twice.

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
| `modules.cache.enabled`             | `true`                                   | Cache the enabled module set so boot does not query the database on every request.                                                                            |
| `modules.cache.store`               | `null`                                   | Cache store used for the registry snapshot. `null` uses the default store.                                                                                    |
| `modules.cache.key`                 | `modules.registry`                       | Cache key used for the registry snapshot.                                                                                                                     |
| `modules.cache.ttl`                 | `3600`                                   | Cache lifetime in seconds.                                                                                                                                    |
| `modules.install.max_archive_bytes` | `268435456`                              | Largest total size a module archive may expand to when installed with `--zip`.                                                                                |
| `modules.install.max_entries`       | `10000`                                  | Maximum files and directories accepted from a module archive or local source.                                                                                 |
| `modules.install.max_path_length`   | `240`                                    | Maximum relative path length inside a module package.                                                                                                         |
| `modules.locks.store`               | `null`                                   | Cache store used for atomic lifecycle locks. `null` uses the default store.                                                                                   |
| `modules.locks.seconds`             | `30`                                     | Lifetime of each lifecycle lock.                                                                                                                              |
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
migrations or seeders, and exits non-zero. Bedrock never downloads dependencies or mutates
the host Composer installation.

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
| `module:make {key} {--backend-only}`          | Generate a full-stack module from package-owned stubs, or a backend-only module. Creates files only.                                                                      |
| `module:validate {source} {--json}`           | Validate source, manifest, autoloading, and built frontend artifacts without executing module code.                                                                       |
| `module:pack {key-or-path} {--output=}`       | Run the declared frontend build from an existing lockfile and create a deterministic, installer-validated ZIP.                                                            |
| `module:install {key}`                        | Install a module directory and its locally available dependencies.                                                                                                        |
| `module:install {key} --from=<dir>`           | Copy a module into the module root, then install it and its locally available dependencies.                                                                               |
| `module:install {key} --zip=<archive>`        | Extract a module into the module root, then install it and its locally available dependencies.                                                                            |
| `module:uninstall {key}`                      | Disable when needed, safely remove module code and the registry row, and retain database data and published resources.                                                    |
| `module:enable {key}`                         | Activate a registered module.                                                                                                                                             |
| `module:disable {key}`                        | Deactivate a module without removing its files or data.                                                                                                                   |
| `module:cache`                                | Rebuild the cached registry of enabled modules.                                                                                                                           |
| `module:clear`                                | Clear the cached registry of enabled modules.                                                                                                                             |
| `frontend:install {--check} {--dry-run}`      | Idempotently integrate a recognized Laravel 13 React starter-kit host without running a package manager.                                                                  |
| `ui:override {target?} {--list}`              | List or seed a conventional host frontend override without overwriting files.                                                                                             |
| `bedrock:admin-install {--check} {--dry-run}` | Inspect the local administration prerequisites and prepare Spatie's publishable configuration and migrations without migrating, modifying the user model, or running npm. |
| `bedrock:admin-user {identifier}`             | Grant the protected `bedrock-admin` role to an existing user identified by primary key or email.                                                                          |
| `bedrock:permissions-sync {module?}`          | Reconcile Bedrock's permission catalog and Spatie permissions from installed module manifests.                                                                            |

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

Use the capability gateways directly in application code:

```php
use Frolax\Bedrock\Facades\Navigation;
use Frolax\Bedrock\Facades\Permissions;
use Frolax\Bedrock\Facades\Settings;

Settings::set('billing', 'currency', 'BDT');
Permissions::can($user, 'billing', 'manage');
Navigation::all($user);
```

Bedrock uses `spatie/laravel-permission` for one configured guard. Add `HasRoles` to the
configured user model, run `php artisan bedrock:admin-install`, migrate after its checks pass,
then grant an existing user access:

```bash
php artisan bedrock:admin-install
php artisan migrate
php artisan bedrock:admin-user admin@example.test
```

## Authoring and packaging modules

Generate a full-stack module or explicitly opt out of frontend files:

```bash
php artisan module:make billing
php artisan module:make audit-log --backend-only
```

The generator only writes files. It never syncs, migrates, installs, or enables the module.
A frontend module owns its package manager lockfile and build dependencies; Bedrock never
installs npm packages or accesses the network on its behalf.

```bash
cd modules/billing
pnpm install
pnpm run types
cd ../..

php artisan module:validate modules/billing
php artisan module:pack billing --output=dist/modules
```

`module:pack` detects npm, pnpm, Yarn, or Bun from `packageManager`/the lockfile, runs only
the existing `build` script, excludes VCS data, `node_modules`, temporary output and
`.bedrockignore` matches, normalizes archive metadata, and validates the completed ZIP
through the installer. It does not install dependencies.

## Frontend host integration

Bedrock supports the Laravel 13 React stack: React 19, Inertia 3, TypeScript, Tailwind 4,
and Vite 8. Install the npm runtime in the host, then let the safe installer compose the
host Inertia resolver and Vite configuration:

```bash
php artisan frontend:install --check
php artisan frontend:install --dry-run
php artisan frontend:install
pnpm install
```

The command recognizes the Laravel React starter-kit layout, writes a dedicated
`resources/js/bedrock-pages.ts`, and refuses unfamiliar/conflicting entry files rather than
overwriting them. It never invokes a package manager.

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

The command starts Laravel, the host Vite server, and the canonical module Vite server.
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
pnpm install
pnpm --filter @modules/example types
pnpm --filter @modules/example build
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
`pnpm frontend:e2e`. Individual
commands are available as `composer analyse`, `composer lint:check`, `composer test:types`
and `composer test:unit`.

## Contributing

Thank you for considering contributing to Bedrock! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Bishwajit Adhikary](https://github.com/frolaxhq)
- [All Contributors](../../contributors)

## License

Bedrock is open-sourced software licensed under the [MIT license](LICENSE.md).
