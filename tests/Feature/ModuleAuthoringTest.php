<?php

declare(strict_types=1);

use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\Exceptions\Module\InvalidModuleSourceException;
use Frolax\Bedrock\Services\Frontend\FrontendHostInstaller;
use Frolax\Bedrock\Services\Module\Authoring\ModulePackager;
use Frolax\Bedrock\Services\Module\Authoring\ModuleScaffolder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

it('generates full stack and backend only modules without lifecycle side effects', function () {
    $root = $this->tempDirectory('authoring-modules');
    config()->set('bedrock.modules.path', $root);

    expect(Artisan::call('module:make', ['key' => 'customer-portal', '--frontend' => true]))->toBe(0)
        ->and(File::exists($root.'/customer-portal/module.json'))->toBeTrue()
        ->and(File::exists($root.'/customer-portal/resources/js/pages/Index.tsx'))->toBeTrue()
        ->and(File::exists($root.'/customer-portal/vite.config.ts'))->toBeTrue()
        ->and(File::get($root.'/customer-portal/.gitignore'))->toContain('/node_modules/')
        ->and(File::exists($root.'/customer-portal/package.json'))->toBeTrue()
        ->and(app(ModuleRepository::class)->find('customer-portal'))->toBeNull();

    $manifest = json_decode(File::get($root.'/customer-portal/module.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($manifest['frontend']['driver'])->toBe('inertia-react')
        ->and($manifest['frontend']['requires']['react'])->toBe('^19.0')
        ->and($manifest['frontend'])->not->toHaveKey('navigation');

    $frontendEntry = File::get($root.'/customer-portal/resources/js/pages.ts');
    $frontendPackage = json_decode(File::get($root.'/customer-portal/package.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($frontendEntry)
        ->toContain('defineNavigation')
        ->toContain('navigationItem')
        ->toContain("route: 'customer-portal.index'")
        ->toContain("    pages: import.meta.glob<{ default: ComponentType<Record<string, unknown>> }>(\n")
        ->toContain("export const {\n    resolvePage,\n")
        ->toContain('IconBox')
        ->and($frontendPackage['dependencies'])
        ->toHaveKey('@tabler/icons-react', '^3.49.0')
        ->and($frontendPackage['devDependencies'])->toHaveKey('vite-plus', '1.0.0')
        ->and($frontendPackage['devDependencies'])->toHaveKey('vite', 'npm:@voidzero-dev/vite-plus-core@1.0.0')
        ->not->toHaveKey('@module-federation/vite')
        ->and($frontendPackage['scripts']['check'])->toBe('vp check')
        ->and($frontendPackage['pnpm']['overrides']['vite@*'])->toBe('npm:@voidzero-dev/vite-plus-core@1.0.0')
        ->and(File::get($root.'/customer-portal/vite.config.ts'))->toContain("import { defineModuleConfig } from '@frolax/bedrock/vite'")
        ->and(File::get($root.'/customer-portal/resources/css/module.css'))->toContain("@import 'tailwindcss/theme.css' layer(theme);")
        ->and(File::get($root.'/customer-portal/resources/js/pages/Index.tsx'))->toContain("<p className=\"mt-2 text-muted-foreground\">\n");

    expect(Artisan::call('module:make', ['key' => 'audit-log', '--backend-only' => true]))->toBe(0)
        ->and(File::exists($root.'/audit-log/package.json'))->toBeFalse()
        ->and(File::exists($root.'/audit-log/resources/js'))->toBeFalse()
        ->and(Artisan::call('module:validate', ['source' => $root.'/audit-log', '--json' => true]))->toBe(0);

    $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($result)->toMatchArray(['ok' => true, 'module' => 'audit-log', 'version' => '1.0.0']);

    File::deleteDirectory($root);
});

it('refuses to overwrite an existing module scaffold', function () {
    $root = $this->tempDirectory('authoring-existing');
    $scaffolder = new ModuleScaffolder(app('files'), $root);
    $scaffolder->create('reports', backendOnly: true);

    expect(fn () => $scaffolder->create('reports', backendOnly: true))
        ->toThrow(InvalidModuleSourceException::class, 'already exists');

    File::deleteDirectory($root);
});

it('creates deterministic validated archives for backend modules', function () {
    $root = $this->tempDirectory('pack-source');
    $output = $this->tempDirectory('pack-output');
    $module = (new ModuleScaffolder(app('files'), $root))->create('reports', backendOnly: true);
    $packager = app(ModulePackager::class);
    $archive = $packager->pack($module, $output);
    $first = hash_file('sha256', $archive);
    $archive = $packager->pack($module, $output, force: true);

    expect($archive)->toBe($output.'/reports-1.0.0.zip')
        ->and(hash_file('sha256', $archive))->toBe($first);

    $zip = new ZipArchive;
    $zip->open($archive);

    expect($zip->locateName('module.json'))->not->toBeFalse()
        ->and($zip->locateName('node_modules'))->toBeFalse()
        ->and($zip->locateName('.git'))->toBeFalse();

    $zip->close();
    File::deleteDirectory($root);
    File::deleteDirectory($output);
});

it('installs host integration idempotently and refuses unfamiliar files without partial edits', function () {
    $root = $this->tempDirectory('frontend-host');
    File::ensureDirectoryExists($root.'/resources/js');
    File::put($root.'/package.json', json_encode([
        'dependencies' => ['@inertiajs/react' => '^3.0.0'],
        'devDependencies' => [],
    ], JSON_THROW_ON_ERROR));
    File::put($root.'/resources/js/app.tsx', "import { createInertiaApp } from '@inertiajs/react';\ncreateInertiaApp({ setup() {} });\n");
    File::put($root.'/resources/js/ssr.tsx', "import { createInertiaApp } from '@inertiajs/react';\ncreateInertiaApp({ setup() {} });\n");
    $hostVite = "import { defineConfig } from 'vite-plus';\nexport default defineConfig({ plugins: [], server: { port: 8123 } });\n";
    File::put($root.'/vite.config.ts', $hostVite);
    $installer = new FrontendHostInstaller(new Filesystem, $root);

    expect($installer->install(dryRun: true))->toContain('Apply Bedrock Vite+ checks in vite.config.ts.')
        ->and(File::get($root.'/vite.config.ts'))->toBe($hostVite)
        ->and($installer->install())->not->toBeEmpty()
        ->and($installer->inspect()['installed'])->toBeTrue()
        ->and($installer->install())->toBe([])
        ->and(File::get($root.'/resources/js/app.tsx'))->toContain("resolve: resolveBedrockPage,\n    setup")
        ->and(File::get($root.'/resources/js/ssr.tsx'))->toContain('resolve: resolveBedrockPage')
        ->and(File::get($root.'/vite.config.ts'))->toContain('bedrockHost()')
        ->and(File::get($root.'/vite.config.ts'))->toContain('const hostConfig = defineConfig({')
        ->and(File::get($root.'/vite.config.ts'))->toContain('export default mergeBedrockToolchain(hostConfig);')
        ->and(File::get($root.'/vite.config.ts'))->not->toContain("from 'vite'")
        ->and(File::get($root.'/vite.config.ts'))->toContain("plugins: [\n        bedrockHost(),")
        ->and(File::get($root.'/vite.config.ts'))->toContain("from '@frolax/bedrock/vite'")
        ->and(File::get($root.'/vite.config.ts'))->toContain('port: 8123')
        ->and(File::get($root.'/resources/js/bedrock-pages.ts'))->toContain('virtual:bedrock-overrides')
        ->and(File::get($root.'/resources/js/bedrock-pages.ts'))->toEndWith("\n")
        ->and(File::get($root.'/resources/js/bedrock-pages.ts'))->not->toContain('bedrockPageOverrides')
        ->and(File::get($root.'/resources/js/bedrock-pages.ts'))->not->toContain('@frolax/bedrock/admin.css')
        ->and(File::get($root.'/resources/css/app.css'))->toContain('@frolax/bedrock/theme.css')
        ->and(File::get($root.'/resources/css/app.css'))->toContain('@frolax/bedrock/dist');

    $legacyPages = str_replace(
        "import 'virtual:bedrock-overrides';",
        "import { bedrockPageOverrides } from 'virtual:bedrock-overrides';",
        File::get($root.'/resources/js/bedrock-pages.ts'),
    );
    $legacyPages = str_replace(
        "createBedrockResolver({\n",
        "createBedrockResolver({\n    pages: bedrockPageOverrides,\n",
        $legacyPages,
    );
    File::put($root.'/resources/js/bedrock-pages.ts', "import '@frolax/bedrock/admin.css';\n".$legacyPages);
    File::put(
        $root.'/resources/js/bedrock-vite.d.ts',
        "declare module 'virtual:bedrock-overrides' { export const bedrockPageOverrides: Record<string, unknown>; }\n",
    );

    expect($installer->inspect()['installed'])->toBeFalse()
        ->and($installer->install())->not->toBeEmpty()
        ->and(File::get($root.'/resources/js/bedrock-pages.ts'))->not->toContain('@frolax/bedrock/admin.css')
        ->and(File::get($root.'/resources/js/bedrock-pages.ts'))->not->toContain('bedrockPageOverrides')
        ->and(File::get($root.'/resources/js/bedrock-vite.d.ts'))->not->toContain('bedrockPageOverrides');

    $hostPackage = json_decode(File::get($root.'/package.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($hostPackage['dependencies'])
        ->toHaveKey('@frolax/bedrock', '^1.0.0')
        ->not->toHaveKey('@base-ui/react')
        ->not->toHaveKey('@tabler/icons-react')
        ->not->toHaveKey('class-variance-authority')
        ->not->toHaveKey('cn')
        ->not->toHaveKey('next-themes')
        ->not->toHaveKey('sonner')
        ->and($hostPackage['devDependencies'])->toHaveKey('vite-plus', '1.0.0')
        ->toHaveKey('vite', 'npm:@voidzero-dev/vite-plus-core@1.0.0')
        ->not->toHaveKey('@module-federation/vite');

    $invalid = $this->tempDirectory('frontend-host-invalid');
    File::ensureDirectoryExists($invalid.'/resources/js');
    $package = json_encode([
        'dependencies' => ['@inertiajs/react' => '^3.0.0'],
        'devDependencies' => ['vite-plus' => '1.0.0'],
    ], JSON_THROW_ON_ERROR);
    File::put($invalid.'/package.json', $package);
    File::put($invalid.'/resources/js/app.tsx', 'unfamiliar app');
    File::put($invalid.'/vite.config.ts', "import { defineConfig } from 'vite-plus';\nexport default defineConfig({ plugins: [] });\n");
    $invalidInstaller = new FrontendHostInstaller(new Filesystem, $invalid);

    expect($invalidInstaller->inspect()['ok'])->toBeFalse()
        ->and($invalidInstaller->inspect()['problems'])->toContain('Refusing to modify the unfamiliar Inertia entry ['.$invalid.'/resources/js/app.tsx].')
        ->and(fn () => $invalidInstaller->install(dryRun: true))
        ->toThrow(InvalidModuleSourceException::class, 'unfamiliar Inertia entry')
        ->and(fn () => $invalidInstaller->install())
        ->toThrow(InvalidModuleSourceException::class, 'unfamiliar Inertia entry')
        ->and(File::get($invalid.'/package.json'))->toBe($package)
        ->and(File::exists($invalid.'/resources/js/bedrock-pages.ts'))->toBeFalse();

    File::deleteDirectory($root);
    File::deleteDirectory($invalid);
});

it('rejects an unfamiliar Vite+ config during preflight without writing host files', function () {
    $root = $this->tempDirectory('frontend-host-unfamiliar-vite');
    File::ensureDirectoryExists($root.'/resources/js');
    $package = json_encode([
        'dependencies' => ['@inertiajs/react' => '^3.0.0'],
        'devDependencies' => ['vite-plus' => '1.0.0'],
    ], JSON_THROW_ON_ERROR);
    File::put($root.'/package.json', $package);
    File::put($root.'/resources/js/app.tsx', "import { createInertiaApp } from '@inertiajs/react';\ncreateInertiaApp({ setup() {} });\n");
    $config = "import { defineConfig } from 'vite-plus';\nexport default defineConfig(() => ({ plugins: [] }));\n";
    File::put($root.'/vite.config.ts', $config);
    $installer = new FrontendHostInstaller(new Filesystem, $root);

    expect($installer->inspect()['ok'])->toBeFalse()
        ->and($installer->inspect()['problems'])->toContain('Refusing to modify the unfamiliar Vite configuration ['.$root.'/vite.config.ts].')
        ->and(fn () => $installer->install(dryRun: true))
        ->toThrow(InvalidModuleSourceException::class, 'unfamiliar Vite configuration')
        ->and(File::get($root.'/package.json'))->toBe($package)
        ->and(File::get($root.'/vite.config.ts'))->toBe($config)
        ->and(File::exists($root.'/resources/js/bedrock-pages.ts'))->toBeFalse();

    File::deleteDirectory($root);
});

it('requires a Vite+ host before frontend integration', function () {
    $root = $this->tempDirectory('frontend-host-legacy-vite');
    File::ensureDirectoryExists($root.'/resources/js');
    $package = json_encode([
        'dependencies' => ['@inertiajs/react' => '^3.0.0'],
        'devDependencies' => ['vite' => '^8.0.0'],
    ], JSON_THROW_ON_ERROR);
    File::put($root.'/package.json', $package);
    File::put($root.'/resources/js/app.tsx', "import { createInertiaApp } from '@inertiajs/react';\ncreateInertiaApp({ setup() {} });\n");
    File::put($root.'/vite.config.ts', "import { defineConfig } from 'vite';\nexport default defineConfig({ plugins: [] });\n");
    $installer = new FrontendHostInstaller(new Filesystem, $root);

    expect($installer->inspect()['problems'])->toContain('Run vp migrate at the host application root before frontend:install.')
        ->and(fn () => $installer->install())->toThrow(InvalidModuleSourceException::class, 'vp migrate')
        ->and(File::get($root.'/package.json'))->toBe($package)
        ->and(File::exists($root.'/resources/js/bedrock-pages.ts'))->toBeFalse();

    File::deleteDirectory($root);
});

it('accepts a Bedrock host factory without rewriting its Vite configuration', function () {
    $root = $this->tempDirectory('frontend-host-factory');
    File::ensureDirectoryExists($root.'/resources/js');
    File::put($root.'/package.json', json_encode([
        'dependencies' => ['@inertiajs/react' => '^3.0.0'],
        'devDependencies' => ['vite-plus' => '1.0.0'],
    ], JSON_THROW_ON_ERROR));
    File::put($root.'/resources/js/app.tsx', "import { createInertiaApp } from '@inertiajs/react';\ncreateInertiaApp({ setup() {} });\n");
    $config = "import { defineHostConfig } from '@frolax/bedrock/vite';\nexport default defineHostConfig({ plugins: [] });\n";
    File::put($root.'/vite.config.ts', $config);
    $installer = new FrontendHostInstaller(new Filesystem, $root);

    expect($installer->install())->not->toBeEmpty()
        ->and(File::get($root.'/vite.config.ts'))->toBe($config)
        ->and($installer->inspect()['installed'])->toBeTrue();

    File::deleteDirectory($root);
});

it('preserves a verified Vite+ pnpm catalog in a migrated React host', function () {
    $root = $this->tempDirectory('frontend-host-catalog');
    File::ensureDirectoryExists($root.'/resources/js');
    File::put($root.'/package.json', json_encode([
        'dependencies' => ['@inertiajs/react' => '^3.0.0', 'vite' => 'catalog:'],
        'devDependencies' => ['vite-plus' => 'catalog:'],
    ], JSON_THROW_ON_ERROR));
    File::put($root.'/pnpm-workspace.yaml', "packages:\n  - '.'\ncatalog:\n  vite: npm:@voidzero-dev/vite-plus-core@1.0.0\n  vite-plus: 1.0.0\n");
    File::put($root.'/resources/js/app.tsx', "import { createInertiaApp } from '@inertiajs/react';\ncreateInertiaApp({ setup() {} });\n");
    File::put($root.'/vite.config.ts', "import { defineConfig } from 'vite-plus';\nexport default defineConfig({ plugins: [] });\n");
    $installer = new FrontendHostInstaller(new Filesystem, $root);

    expect($installer->inspect()['ok'])->toBeTrue()
        ->and($installer->install(dryRun: true))->not->toBeEmpty()
        ->and($installer->install())->not->toBeEmpty()
        ->and($installer->inspect()['installed'])->toBeTrue();

    $package = json_decode(File::get($root.'/package.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($package['dependencies']['vite'])->toBe('catalog:')
        ->and($package['devDependencies']['vite-plus'])->toBe('catalog:')
        ->and($installer->install())->toBe([]);

    File::put($root.'/pnpm-workspace.yaml', "packages:\n  - '.'\ncatalog:\n  vite: ^8.0.0\n  vite-plus: 1.0.0\n");

    expect($installer->inspect()['ok'])->toBeFalse();

    File::deleteDirectory($root);
});

it('seeds conventional frontend overrides without overwriting host files', function () {
    $root = $this->tempDirectory('frontend-overrides');
    app()->setBasePath($root);

    expect(Artisan::call('ui:override', ['target' => 'button']))->toBe(0)
        ->and(File::exists($root.'/resources/js/components/ui/button.tsx'))->toBeTrue()
        ->and(File::get($root.'/resources/js/components/ui/button.tsx'))->toContain("from '@frolax/bedrock/lib'")
        ->and(Artisan::output())->toContain('components/ui/button');

    File::put($root.'/resources/js/components/ui/button.tsx', 'host implementation');

    expect(Artisan::call('ui:override', ['target' => 'button']))->toBe(1)
        ->and(File::get($root.'/resources/js/components/ui/button.tsx'))->toBe('host implementation')
        ->and(Artisan::output())->toContain('already exists');

    expect(Artisan::call('ui:override', ['target' => 'layouts/app-layout']))->toBe(0)
        ->and(File::exists($root.'/resources/js/layouts/app-layout.tsx'))->toBeTrue()
        ->and(File::get($root.'/resources/js/layouts/app-layout.tsx'))->toContain("from '@frolax/bedrock/ui'")
        ->toContain("from '@frolax/bedrock/react'")
        ->and(Artisan::call('ui:override', ['target' => 'layouts/auth-layout']))->toBe(0)
        ->and(File::exists($root.'/resources/js/layouts/auth-layout.tsx'))->toBeTrue()
        ->and(Artisan::call('ui:override', ['--list' => true]))->toBe(0);

    $catalog = Artisan::output();

    expect($catalog)->toContain('components/ui/button')
        ->toContain('hooks/use-theme')
        ->toContain('layouts/auth-layout')
        ->not->toContain('layouts/panel-layout');

    File::deleteDirectory($root);
});
