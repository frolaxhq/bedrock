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

    expect(Artisan::call('module:make', ['key' => 'customer-portal']))->toBe(0)
        ->and(File::exists($root.'/customer-portal/module.json'))->toBeTrue()
        ->and(File::exists($root.'/customer-portal/resources/js/pages/Index.tsx'))->toBeTrue()
        ->and(File::exists($root.'/customer-portal/vite.config.ts'))->toBeTrue()
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
        ->toContain('IconBox')
        ->and($frontendPackage['dependencies'])
        ->toHaveKey('@tabler/icons-react', '^3.48.0');

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
    ], JSON_THROW_ON_ERROR));
    File::put($root.'/resources/js/app.tsx', "import { createInertiaApp } from '@inertiajs/react';\ncreateInertiaApp({ setup() {} });\n");
    File::put($root.'/resources/js/ssr.tsx', "import { createInertiaApp } from '@inertiajs/react';\ncreateInertiaApp({ setup() {} });\n");
    File::put($root.'/vite.config.ts', "import { defineConfig } from 'vite';\nexport default defineConfig({ plugins: [] });\n");
    $installer = new FrontendHostInstaller(new Filesystem, $root);

    expect($installer->install())->not->toBeEmpty()
        ->and($installer->inspect()['installed'])->toBeTrue()
        ->and($installer->install())->toBe([])
        ->and(File::get($root.'/resources/js/app.tsx'))->toContain('resolve: resolveBedrockPage')
        ->and(File::get($root.'/resources/js/ssr.tsx'))->toContain('resolve: resolveBedrockPage')
        ->and(File::get($root.'/vite.config.ts'))->toContain('bedrockHost()')
        ->and(File::get($root.'/resources/js/bedrock-pages.ts'))->toContain('virtual:bedrock-overrides')
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
        ->toHaveKey('@base-ui/react', '^1.8.0')
        ->toHaveKey('@tabler/icons-react', '^3.48.0')
        ->toHaveKey('@frolax/bedrock', '^1.0.0');

    $invalid = $this->tempDirectory('frontend-host-invalid');
    File::ensureDirectoryExists($invalid.'/resources/js');
    $package = json_encode(['dependencies' => ['@inertiajs/react' => '^3.0.0']], JSON_THROW_ON_ERROR);
    File::put($invalid.'/package.json', $package);
    File::put($invalid.'/resources/js/app.tsx', 'unfamiliar app');
    File::put($invalid.'/vite.config.ts', 'unfamiliar vite');
    $invalidInstaller = new FrontendHostInstaller(new Filesystem, $invalid);

    expect(fn () => $invalidInstaller->install())
        ->toThrow(InvalidModuleSourceException::class, 'unfamiliar Inertia entry')
        ->and(File::get($invalid.'/package.json'))->toBe($package)
        ->and(File::exists($invalid.'/resources/js/bedrock-pages.ts'))->toBeFalse();

    File::deleteDirectory($root);
    File::deleteDirectory($invalid);
});

it('seeds conventional frontend overrides without overwriting host files', function () {
    $root = $this->tempDirectory('frontend-overrides');
    app()->setBasePath($root);

    expect(Artisan::call('ui:override', ['target' => 'button']))->toBe(0)
        ->and(File::exists($root.'/resources/js/components/ui/button.tsx'))->toBeTrue()
        ->and(File::get($root.'/resources/js/components/ui/button.tsx'))->toContain('from "@frolax/bedrock/lib"')
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
