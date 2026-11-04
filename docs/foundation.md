# Auth, admin and shared UI

## Fresh Laravel application

Use Laravel 13, PHP 8.5+, Node 26.13+ (26 LTS), npm, a configured database and a mail
transport. Install `frolaxhq/bedrock` and run `php artisan bedrock:install`.
The installer prepares files, runs migrations, installs/builds frontend dependencies
and prompts for the first administrator. It never creates a default password.

`--dry-run` lists file changes without modifying the application. `--check` checks
files, permission tables/User integration, the production asset manifest and enabled
configuration. `--skip-frontend` leaves `npm install && npm run bedrock:build` pending;
`--skip-admin` leaves `php artisan bedrock:admin-create` pending. Non-interactive setup
also defers administrator creation. Failed steps return a nonzero exit status and can
be retried. Files already matching generated output are left alone; customized generated
files produce conflicts instead of being overwritten. Keep customized files and perform
remaining integration steps manually rather than deleting customizations to rerun setup.

Preflight checks reject conflicting auth/admin route names or paths, incompatible
password/permission guards, and malformed package metadata before writing files.
The generated frontend uses aligned Vite+ 1.0.0 packages; an existing incompatible
Vite+ version requires manual integration. Generated npm overrides keep Vitest at
5.0.1 to match the Vite+ 1.0.0 browser testing peers; conflicting existing Vitest
dependencies or overrides require manual alignment before installation.

Use `npm run bedrock:dev` during development. The package's root Blade view loads
`resources/js/bedrock.tsx` and `resources/css/bedrock.css`. The dedicated
`vite.bedrock.config.ts` leaves the application's existing Vite configuration intact.
This build becomes the host entry for Bedrock pages and modules; use it for new project
pages too. An application maintaining another independent build must configure distinct
build directories instead of allowing two builds to overwrite `public/build`.

## Configuration and routes

The installer enables `bedrock.auth.enabled`, `bedrock.admin.enabled` and
`bedrock.administration.enabled`. Package installation alone leaves these routes disabled.

- `bedrock.auth.registration`: public registration, default `false`.
- `bedrock.admin.path`: dashboard URI, default `admin`.
- `bedrock.brand.name`: application display name; null uses `app.name`.
- `bedrock.brand.logo`: optional public image URL for the sidebar and auth screens.
- `bedrock.admin.navigation`: project links with `label`, named `route`, and optional
  `permission`. Routes requiring parameters are not supported by this static list.

Auth endpoints are `/login`, `/logout`, `/register`, `/forgot-password`,
`/reset-password/{token}`, `/verify-email`, `/email/verification-notification`,
`/profile` and `/password`. They use the `web` guard, Laravel's default password broker,
CSRF protection, session regeneration, login throttling and signed verification links.
Registration only sets name, email and password; it never grants roles.
Profile email changes clear verification. Verification is available to applications;
the dashboard requires authentication/admin permission, not a verified-email gate.
Protect business routes with `verified` when required by the project.

`/admin` requires the configured `bedrock.permissions.admin_permission` (`bedrock.admin`).
Use `bedrock:admin-create` for a new account or `bedrock:admin-user {email-or-id}` to grant
an existing account access. The existing Blade console remains at `/bedrock/admin`.
Existing console and module features are retained; media, CMS, tenancy and 2FA are not
part of this foundation increment.

## Project pages and navigation

Create `resources/js/pages/Reports.tsx`:

```tsx
import { AppLayout } from '@frolax/bedrock/ui';

export default function Reports() {
    return (
        <AppLayout title="Reports">
            <div className="p-6">Your project reports.</div>
        </AppLayout>
    );
}
```

Register the route in the application's `routes/web.php`:

```php
use Frolax\Bedrock\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth:web', 'can:bedrock.admin', HandleInertiaRequests::class])
    ->get('/admin/reports', fn () => Inertia::render('Reports'))
    ->name('reports.index');
```

Add `['label' => 'Reports', 'route' => 'reports.index', 'permission' => 'bedrock.admin']`
to `bedrock.admin.navigation`. The middleware supplies the viewer, branding, flash
messages and authorized navigation. Hiding a link does not authorize its destination;
protect every route. Existing frontend modules continue using their manifest/provider,
shared module navigation and `module:KEY/Page` resolution. Installing a prebuilt module
does not require rebuilding the host frontend.

## Overrides and existing applications

Use `php artisan ui:override --list` and, for example,
`php artisan ui:override layouts/app-layout`. Components/layouts in conventional host
paths override package defaults. The generated entry imports `virtual:bedrock-overrides`.
To replace a built-in page, add `resources/js/pages/bedrock/Login.tsx` (or the matching
page name); local pages take precedence over `@frolax/bedrock/pages` defaults.
Keep the package defaults installed so unmodified components receive updates.

The automatic installer targets the standard `App\Models\User` and `web` guard. For an
existing application, first resolve route collisions and retain its authentication if
appropriate. Add `HasRoles` and the `MustVerifyEmail` contract to its authenticatable
User model, prepare Spatie permission migrations once, and use Laravel's standard
users/password-reset schema. Configure the default password broker to use the same
user provider as `web`. Do not enable Bedrock auth beside competing auth routes.

Integrate `resolveFoundationPage` from `@frolax/bedrock/pages` as the fallback of the
existing `createBedrockResolver` local resolver, keep `ThemeProvider` around the Inertia
app, and use the foundation Inertia middleware for Bedrock pages. Enable the desired
config sections and run migrations/build explicitly. Do not overwrite customized
config, User models, application entries or route files with package templates.

## Verification

Run `composer test`, `pnpm frontend:check`, `pnpm frontend:test` and
`composer test:consumer`. The consumer suite verifies repeated installation and the
existing isolated module runtime in fresh Laravel applications. Set
`BEDROCK_KEEP_CONSUMER=1` to retain the temporary consumers for inspection.

For an unreleased npm package, run `pnpm frontend:build`, pack `@frolax/bedrock` locally,
set the retained consumer's dependency to the resulting tarball, run
`php /absolute/bedrock/scripts/consumer-foundation-fixture.php` inside it, and build it. Give the
consumer a persistent session driver and seed the smoke-test administrator. Run the
foundation browser suite with `BEDROCK_CONSUMER_PATH=/absolute/consumer/path pnpm --filter
@frolax/bedrock exec playwright test --config playwright.foundation.config.ts`.
The fixture account is `admin@example.test` / `password`, created only by the consumer
smoke test. Never use those credentials outside a disposable test application.
