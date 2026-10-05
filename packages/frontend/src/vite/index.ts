import { federation } from '@module-federation/vite';
import fs from 'node:fs';
import path from 'node:path';
import type { PluginOption, UserConfig } from 'vite';

interface BedrockHostOptions {
    root?: string;
}

interface CatalogEntry {
    id: string;
    source: string;
}

interface Catalog {
    entries: CatalogEntry[];
}

interface PanelFiles {
    key: string;
    shell?: string;
    slots: Record<string, string>;
}

interface BedrockModuleOptions {
    key: string;
    entry: string;
    css?: string;
    ssr?: boolean;
    plugins?: PluginOption[];
    hostRoot?: string;
}

const hostShared = {
    react: { singleton: true },
    'react/jsx-runtime': { singleton: true },
    'react/jsx-dev-runtime': { singleton: true },
    'react-dom': { singleton: true },
    'react-dom/client': { singleton: true },
    'react-dom/server': { singleton: true },
    '@inertiajs/react': { singleton: true },
    '@inertiajs/core': { singleton: true },
    '@frolax/bedrock/react': { singleton: true },
    '@frolax/bedrock/module': { singleton: true },
    '@frolax/bedrock/ui': { singleton: true },
    '@frolax/bedrock/theme': { singleton: true },
    '@frolax/bedrock/ui-runtime': { singleton: true },
};

const moduleShared = {
    react: { singleton: true, import: false as const },
    'react/jsx-runtime': { singleton: true, import: false as const },
    'react/jsx-dev-runtime': { singleton: true, import: false as const },
    'react-dom': { singleton: true, import: false as const },
    'react-dom/client': { singleton: true, import: false as const },
    'react-dom/server': { singleton: true, import: false as const },
    '@inertiajs/react': { singleton: true, import: false as const },
    '@inertiajs/core': { singleton: true, import: false as const },
    '@frolax/bedrock/react': { singleton: true, import: false as const },
    '@frolax/bedrock/module': { singleton: true, import: false as const },
    '@frolax/bedrock/ui': { singleton: true, import: false as const },
    '@frolax/bedrock/theme': { singleton: true, import: false as const },
    '@frolax/bedrock/ui-runtime': { singleton: true, import: false as const },
};

const virtualOverrides = 'virtual:bedrock-overrides';
const resolvedVirtualOverrides = `\0${virtualOverrides}`;

export function bedrockHost(options: BedrockHostOptions = {}): PluginOption {
    return [
        federation({
            name: 'bedrock_host',
            remotes: {},
            shared: {
                ...hostShared,
            },
        }),
        frontendOverrides(options),
        {
            name: 'bedrock:host-versions',
            config() {
                const versions = dependencyVersions(process.cwd());

                return {
                    define: {
                        'globalThis.__BEDROCK_SHARED_VERSIONS__': JSON.stringify(versions),
                    },
                };
            },
        },
    ];
}

function frontendOverrides(options: BedrockHostOptions): PluginOption {
    const root = path.resolve(options.root ?? process.cwd());
    const sourceRoot = path.join(root, 'resources/js');

    return {
        name: 'bedrock:frontend-overrides',
        resolveId(id) {
            return id === virtualOverrides ? resolvedVirtualOverrides : undefined;
        },
        load(id) {
            if (id !== resolvedVirtualOverrides) return undefined;

            return frontendBootstrapModule(sourceRoot);
        },
        configureServer(server) {
            server.watcher.add([
                path.join(sourceRoot, 'components'),
                path.join(sourceRoot, 'hooks'),
                path.join(sourceRoot, 'layouts'),
                path.join(sourceRoot, 'panels'),
                path.join(sourceRoot, 'navigation.ts'),
            ]);

            const refresh = (file: string) => {
                if (!catalogId(sourceRoot, file) && !isPanelSource(sourceRoot, file) && !isNavigationSource(sourceRoot, file)) return;

                const module = server.moduleGraph.getModuleById(resolvedVirtualOverrides);

                if (module) server.moduleGraph.invalidateModule(module);
                server.ws.send({ type: 'full-reload' });
            };

            server.watcher.on('add', refresh);
            server.watcher.on('unlink', refresh);
        },
    };
}

function frontendBootstrapModule(sourceRoot: string): string {
    const eager: string[] = [];
    const registrations: string[] = [];
    let index = 0;

    for (const entry of catalog().entries) {
        const extension = entry.source.endsWith('.ts') ? '.ts' : '.tsx';
        const file = path.join(sourceRoot, `${entry.id}${extension}`);

        if (!fs.existsSync(file)) continue;

        const specifier = JSON.stringify(file.replace(/\\/g, '/'));

        const variable = `override${index++}`;
        eager.push(`import * as ${variable} from ${specifier};`);
        registrations.push(`${JSON.stringify(entry.id)}: ${variable}`);
    }

    const panelRegistrations: string[] = [];

    for (const panel of discoverPanels(sourceRoot)) {
        const definition: string[] = [];

        if (panel.shell) {
            const variable = `panel${index++}`;
            eager.push(`import ${variable} from ${JSON.stringify(panel.shell)};`);
            definition.push(`shell: ${variable}`);
        }

        const slots: string[] = [];

        for (const [slot, file] of Object.entries(panel.slots)) {
            const variable = `panel${index++}`;
            eager.push(`import ${variable} from ${JSON.stringify(file)};`);
            slots.push(`${JSON.stringify(slot)}: ${variable}`);
        }

        definition.push(`slots: {${slots.join(',')}}`);
        panelRegistrations.push(`${JSON.stringify(panel.key)}: {${definition.join(',')}}`);
    }

    const runtimeSpecifier = ['@frolax/bedrock', 'ui-runtime'].join('/');
    const reactSpecifier = ['@frolax/bedrock', 'react'].join('/');
    const navigation = path.join(sourceRoot, 'navigation.ts');

    if (fs.existsSync(navigation)) {
        eager.push(`import hostNavigation from ${JSON.stringify(normalizeSpecifier(navigation))};`);
    }

    return [
        `import { registerFrontendOverrides, registerFrontendPanels } from ${JSON.stringify(runtimeSpecifier)};`,
        `import { registerHostNavigation } from ${JSON.stringify(reactSpecifier)};`,
        ...eager,
        `registerFrontendOverrides({${registrations.join(',')}});`,
        `registerFrontendPanels({${panelRegistrations.join(',')}});`,
        `registerHostNavigation(${fs.existsSync(navigation) ? 'hostNavigation' : 'undefined'});`,
        ...(fs.existsSync(navigation) ? [
            `if (import.meta.hot) import.meta.hot.accept(${JSON.stringify(normalizeSpecifier(navigation))}, (updated) => registerHostNavigation(updated?.default));`,
        ] : []),
    ].join('\n');
}

function isNavigationSource(sourceRoot: string, file: string): boolean {
    return path.resolve(file) === path.join(sourceRoot, 'navigation.ts');
}

function discoverPanels(sourceRoot: string): PanelFiles[] {
    const panelsRoot = path.join(sourceRoot, 'panels');

    if (!fs.existsSync(panelsRoot)) return [];

    const panels = new Map<string, PanelFiles>();
    const entries = fs.readdirSync(panelsRoot, { recursive: true, withFileTypes: true })
        .filter((entry) => entry.isFile() && /\.[tj]sx?$/i.test(entry.name))
        .map((entry) => path.join(entry.parentPath, entry.name))
        .sort((left, right) => left.localeCompare(right, 'en'));

    for (const file of entries) {
        const relative = path.relative(panelsRoot, file).replace(/\\/g, '/');
        const shell = /^([a-z][a-z0-9-]*)\/shell\.tsx$/.exec(relative);
        const slot = /^([a-z][a-z0-9-]*)\/slots\/([a-z][a-z0-9-]*)\.tsx$/.exec(relative);

        if (!shell && !slot) {
            throw new Error(
                `Panel file [resources/js/panels/${relative}] must use ` +
                '[panel]/shell.tsx or [panel]/slots/[slot].tsx with lowercase kebab-case names.',
            );
        }

        const key = (shell?.[1] ?? slot?.[1])!;
        const panel = panels.get(key) ?? { key, slots: {} };

        if (shell) {
            panel.shell = normalizeSpecifier(file);
        } else {
            panel.slots[slot![2]!] = normalizeSpecifier(file);
        }

        panels.set(key, panel);
    }

    return [...panels.values()].sort((left, right) => left.key.localeCompare(right.key, 'en'));
}

function catalogId(sourceRoot: string, file: string): string | undefined {
    const relative = path.relative(sourceRoot, file).replace(/\\/g, '/').replace(/\.tsx?$/, '');

    return catalog().entries.some((entry) => entry.id === relative) ? relative : undefined;
}

function isPanelSource(sourceRoot: string, file: string): boolean {
    const panelsRoot = path.join(sourceRoot, 'panels');
    const relative = path.relative(panelsRoot, file);

    return relative !== '' && !relative.startsWith('..') && !path.isAbsolute(relative) && /\.[tj]sx?$/i.test(relative);
}

function normalizeSpecifier(file: string): string {
    return file.replace(/\\/g, '/');
}

let cachedCatalog: Catalog | undefined;

function catalog(): Catalog {
    if (!cachedCatalog) {
        const file = new URL('../../catalog.json', import.meta.url);
        cachedCatalog = JSON.parse(fs.readFileSync(file, 'utf8')) as Catalog;
    }

    return cachedCatalog;
}

export function bedrockModule(options: BedrockModuleOptions): UserConfig {
    const plugins = [...(options.plugins ?? [])];

    if (!options.ssr) {
        plugins.push(
            federation({
                name: federationName(options.key),
                filename: 'remoteEntry.js',
                manifest: true,
                exposes: { './module': path.resolve(options.entry) },
                shared: moduleShared,
            }),
            developmentDescriptor(options),
        );
    }

    return {
        base: './',
        plugins,
        build: options.ssr
            ? {
                  outDir: 'dist/bedrock/server',
                  emptyOutDir: true,
                  ssr: path.resolve(options.entry),
                  rollupOptions: {
                      external: [
                          'react',
                          'react-dom',
                          'react-dom/server',
                          '@inertiajs/react',
                          '@inertiajs/core',
                          '@frolax/bedrock/react',
                          '@frolax/bedrock/module',
                          '@frolax/bedrock/ui',
                          '@frolax/bedrock/theme',
                          '@frolax/bedrock/ui-runtime',
                      ],
                      output: { entryFileNames: 'entry.mjs', chunkFileNames: 'chunks/[name]-[hash].mjs' },
                  },
              }
              : {
                  outDir: 'dist/bedrock/client',
                  emptyOutDir: true,
                  target: 'es2022',
                  rollupOptions: {
                      input: path.resolve(options.entry),
                  },
              },
    };
}

function developmentDescriptor(options: BedrockModuleOptions): PluginOption {
    let timer: ReturnType<typeof setInterval> | undefined;

    return {
        name: 'bedrock:development-descriptor',
        configureServer(server) {
            const root = path.resolve(options.hostRoot ?? '../..');
            const directory = path.join(root, 'storage/framework/bedrock/frontend-dev');
            const file = path.join(directory, `${options.key}.json`);
            const clean = () => fs.rmSync(file, { force: true });
            const write = () => {
                fs.mkdirSync(directory, { recursive: true });
                fs.writeFileSync(
                    file,
                    `${JSON.stringify({
                        key: options.key,
                        client: new URL('remoteEntry.js', server.resolvedUrls?.local[0] ?? `http://localhost:${server.config.server.port ?? 5173}/`).href,
                        server: path.join(process.cwd(), 'dist/bedrock/server/entry.mjs'),
                        css: [],
                        expires_at: Math.floor(Date.now() / 1000) + 30,
                    }, null, 2)}\n`,
                );
            };

            server.httpServer?.once('listening', write);
            timer = setInterval(write, 10_000);
            process.once('exit', clean);
            server.httpServer?.once('close', () => {
                if (timer) clearInterval(timer);
                process.removeListener('exit', clean);
                clean();
            });
        },
    };
}

function dependencyVersions(root: string): Record<string, string> {
    const packagePath = path.join(root, 'package.json');

    if (!fs.existsSync(packagePath)) return {};

    const packageJson = JSON.parse(fs.readFileSync(packagePath, 'utf8')) as {
        dependencies?: Record<string, string>;
        devDependencies?: Record<string, string>;
    };
    const declarations = { ...packageJson.devDependencies, ...packageJson.dependencies };
    const result: Record<string, string> = {};

    for (const dependency of ['react', 'react-dom', '@inertiajs/react', '@frolax/bedrock']) {
        const installedPackage = path.join(root, 'node_modules', dependency, 'package.json');

        if (fs.existsSync(installedPackage)) {
            const installed = JSON.parse(fs.readFileSync(installedPackage, 'utf8')) as { version?: string };

            if (installed.version) {
                result[dependency] = installed.version;
                continue;
            }
        }

        const declared = declarations[dependency];

        if (declared) {
            const version = /(\d+(?:\.\d+){0,2}(?:-[0-9A-Za-z.-]+)?)/.exec(declared)?.[1];

            if (version) result[dependency] = version;
        }
    }

    return result;
}

function federationName(key: string) {
    return `bedrock_${key.replace(/-/g, '_')}`;
}
