// @vitest-environment node

import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import {
    bedrockHost,
    bedrockModule,
    bedrockToolchain,
    defineHostConfig,
    defineModuleConfig,
    mergeBedrockToolchain,
} from '../../src/vite/index.js';

interface PluginHarness {
    name?: string;
    resolveId(id: string): string | undefined;
    load(id: string): string | undefined;
    configureServer(server: {
        watcher: {
            add(paths: string[]): void;
            on(event: 'add' | 'unlink', handler: (file: string) => void): void;
        };
        moduleGraph: {
            getModuleById(id: string): object | undefined;
            invalidateModule(module: object): void;
        };
        ws: { send(payload: { type: string }): void };
    }): void;
}

const roots: string[] = [];

describe('panel convention plugin', () => {
    it('publishes host and module plugins with the shared Vite+ toolchain', () => {
        const manifest = JSON.parse(
            readFileSync(new URL('../../package.json', import.meta.url), 'utf8'),
        ) as {
            exports: Record<string, unknown>;
        };

        expect(bedrockHost).toBeTypeOf('function');
        expect(bedrockModule).toBeTypeOf('function');
        expect(bedrockToolchain.lint?.options?.typeCheck).toBe(true);
        expect(bedrockToolchain.lint?.ignorePatterns).toContain('node_modules/**');
        expect(manifest.exports['./vite']).toEqual({
            types: './dist/vite/index.d.ts',
            import: './dist/vite/index.js',
        });
        expect(manifest.exports['./vite-plus']).toBeUndefined();
    });

    it('supplies Bedrock host defaults while preserving explicit application settings', () => {
        const config = defineHostConfig({
            server: { port: 8123 },
            lint: { rules: { 'no-console': 'off' } },
        });

        expect(config.server?.port).toBe(8123);
        expect(config.lint?.rules?.['no-console']).toBe('off');
        expect(config.lint?.options?.typeCheck).toBe(true);
        expect(config.plugins).toBeDefined();
        const merged = mergeBedrockToolchain({ lint: { rules: { 'no-console': 'off' } } });
        expect(merged.lint?.options?.typeCheck).toBe(true);
        expect(merged.lint?.rules?.['no-console']).toBe('off');
        expect(merged.lint?.ignorePatterns).toContain('node_modules/**');
    });

    it('supplies module client and SSR defaults with explicit overrides', () => {
        const config = defineModuleConfig({ key: 'example', vite: { server: { port: 5180 } } });
        const client = config({ command: 'build', mode: 'production', isSsrBuild: false });
        const server = config({ command: 'build', mode: 'production', isSsrBuild: true });

        expect(client.server?.port).toBe(5180);
        expect(client.lint?.options?.typeCheck).toBe(true);
        expect(client.build?.outDir).toBe('dist/bedrock/client');
        expect(client.plugins).toBeDefined();
        expect(server.build?.outDir).toBe('dist/bedrock/server');
    });
    afterEach(async () => {
        await Promise.all(
            roots.splice(0).map((root) => rm(root, { force: true, recursive: true })),
        );
    });

    it('generates the same eager panel registry for client and SSR builds', async () => {
        const root = await workspace();
        await source(
            root,
            'panels/default/slots/sidebar.tsx',
            'export default function Sidebar() { return null }',
        );
        await source(
            root,
            'panels/admin/shell.tsx',
            'export default function Shell() { return null }',
        );
        await source(
            root,
            'panels/admin/slots/sidebar-footer.tsx',
            'export default function Footer() { return null }',
        );
        const plugin = overridePlugin(root);
        const id = plugin.resolveId('virtual:bedrock-overrides')!;
        const generated = plugin.load(id)!;

        expect(generated).toContain('registerFrontendPanels');
        expect(generated).toContain('"default": {slots: {"sidebar": panel');
        expect(generated).toContain('"admin": {shell: panel');
        expect(generated).toContain('"sidebar-footer": panel');
        expect(generated).toContain('import panel');
    });

    it('auto-registers conventional host TypeScript navigation with HMR', async () => {
        const root = await workspace();
        await source(root, 'navigation.ts', 'export default { items: [] }');
        const plugin = overridePlugin(root);
        const id = plugin.resolveId('virtual:bedrock-overrides')!;
        const generated = plugin.load(id)!;

        expect(generated).toContain('import hostNavigation from');
        expect(generated).toContain('resources/js/navigation.ts');
        expect(generated).toContain('registerHostNavigation(hostNavigation)');
        expect(generated).toContain('import.meta.hot.accept');
    });

    it('rejects panel files outside the lowercase kebab-case convention', async () => {
        const root = await workspace();
        await source(
            root,
            'panels/Admin/slots/sidebar.tsx',
            'export default function Sidebar() { return null }',
        );
        const plugin = overridePlugin(root);
        const id = plugin.resolveId('virtual:bedrock-overrides')!;

        expect(() => plugin.load(id)).toThrow('lowercase kebab-case');
    });

    it('emits default imports so missing component exports fail the Vite build', async () => {
        const root = await workspace();
        await source(root, 'panels/default/shell.tsx', 'export const Shell = () => null');
        const plugin = overridePlugin(root);
        const id = plugin.resolveId('virtual:bedrock-overrides')!;
        const generated = plugin.load(id)!;

        expect(generated).toMatch(/import panel\d+ from .*panels\/default\/shell\.tsx/);
    });

    it('watches panel conventions and reloads when a shell or slot is added or removed', async () => {
        const root = await workspace();
        const handlers = new Map<string, (file: string) => void>();
        const add = vi.fn();
        const invalidateModule = vi.fn();
        const send = vi.fn();
        const module = {};
        const plugin = overridePlugin(root);

        plugin.configureServer({
            watcher: {
                add,
                on: (event, handler) => handlers.set(event, handler),
            },
            moduleGraph: {
                getModuleById: () => module,
                invalidateModule,
            },
            ws: { send },
        });

        expect(add).toHaveBeenCalledWith(
            expect.arrayContaining([path.join(root, 'resources/js/panels')]),
        );

        const panel = path.join(root, 'resources/js/panels/admin/slots/sidebar-footer.tsx');
        handlers.get('add')?.(panel);

        expect(invalidateModule).toHaveBeenCalledWith(module);
        expect(send).toHaveBeenCalledWith({ type: 'full-reload' });

        handlers.get('unlink')?.(path.join(root, 'resources/js/pages/dashboard.tsx'));
        expect(send).toHaveBeenCalledOnce();
    });

    it('keeps the shared Bedrock UI and theme external to module SSR bundles', () => {
        const config = bedrockModule({ key: 'billing', entry: 'resources/js/pages.ts', ssr: true });
        const external =
            config.build && 'rollupOptions' in config.build
                ? config.build.rollupOptions?.external
                : undefined;

        expect(external).toEqual(
            expect.arrayContaining([
                '@frolax/bedrock/react',
                '@frolax/bedrock/module',
                '@frolax/bedrock/ui',
                '@frolax/bedrock/theme',
                '@frolax/bedrock/ui-runtime',
            ]),
        );
    });
});

async function workspace(): Promise<string> {
    const root = await mkdtemp(path.join(tmpdir(), 'bedrock-panels-'));
    roots.push(root);

    return root;
}

async function source(root: string, relative: string, contents: string): Promise<void> {
    const file = path.join(root, 'resources/js', relative);
    await mkdir(path.dirname(file), { recursive: true });
    await writeFile(file, contents);
}

function overridePlugin(root: string): PluginHarness {
    const plugins = bedrockHost({ root }) as unknown as PluginHarness[];
    const plugin = plugins.find((candidate) => candidate.name === 'bedrock:frontend-overrides');

    if (!plugin) throw new Error('Bedrock frontend override plugin was not registered.');

    return plugin;
}
