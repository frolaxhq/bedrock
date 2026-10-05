import react from '@vitejs/plugin-react';
import { expect, test } from '@playwright/test';
import { mkdir, rm, symlink, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { createServer, type ViteDevServer } from 'vite';
import { bedrockHost } from '../src/vite/index.js';

const packageRoot = path.resolve(import.meta.dirname, '..');
const fixtureRoot = path.resolve(import.meta.dirname, '.artifacts/hmr-host');
const panelSlot = path.join(fixtureRoot, 'resources/js/panels/default/slots/sidebar.tsx');
const panelHeader = path.join(fixtureRoot, 'resources/js/panels/default/slots/header.tsx');
const navigation = path.join(fixtureRoot, 'resources/js/navigation.ts');
let server: ViteDevServer;

test.beforeAll(async () => {
    await rm(fixtureRoot, { recursive: true, force: true });
    await mkdir(path.dirname(panelSlot), { recursive: true });
    await mkdir(path.join(fixtureRoot, 'src'), { recursive: true });
    await mkdir(path.join(fixtureRoot, 'node_modules/@frolax'), { recursive: true });
    await symlink(
        packageRoot,
        path.join(fixtureRoot, 'node_modules/@frolax/bedrock'),
        process.platform === 'win32' ? 'junction' : 'dir',
    );
    await writeFile(
        path.join(fixtureRoot, 'index.html'),
        '<div id="root"></div><script type="module" src="/src/main.tsx"></script>',
    );
    await writeFile(
        path.join(fixtureRoot, 'tsconfig.json'),
        JSON.stringify({
            compilerOptions: {
                jsx: 'react-jsx',
                module: 'ESNext',
                moduleResolution: 'Bundler',
                target: 'ES2022',
            },
            include: ['src', 'resources'],
        }),
    );
    await writeFile(panelSlot, panelSource('First panel version'));
    await writeFile(panelHeader, 'export default function Header() { return <header>Header</header>; }\n');
    await writeFile(navigation, navigationSource('First navigation version'));
    await writeFile(
        path.join(fixtureRoot, 'src/main.tsx'),
        `
            import React from 'react';
            import { createRoot } from 'react-dom/client';
            import { PanelProvider, PanelShell } from '@frolax/bedrock/ui';
            import { createBedrockResolver, useNavigation } from '@frolax/bedrock/react';
            import 'virtual:bedrock-overrides';

            const loads = Number(sessionStorage.getItem('bedrock-hmr-loads') ?? '0') + 1;
            sessionStorage.setItem('bedrock-hmr-loads', String(loads));
            document.body.dataset.loads = String(loads);

            const resolve = createBedrockResolver({ local: async () => ({ default: () => null }) });

            function NavigationProbe() {
                const resolved = useNavigation();
                return <output data-navigation>{resolved.items[0]?.label}</output>;
            }

            void resolve('Home', {
                component: 'Home',
                url: '/',
                props: { __bedrock: { schema: 1, modules: {}, navigation: [] } },
            }).then(() => createRoot(document.getElementById('root')!).render(
                <>
                    <NavigationProbe />
                    <PanelProvider><PanelShell>Workspace</PanelShell></PanelProvider>
                </>,
            ));
        `,
    );

    server = await createServer({
        root: fixtureRoot,
        plugins: [react(), bedrockHost({ root: fixtureRoot })],
        server: { host: '127.0.0.1', port: 4191, strictPort: true },
    });
    await server.listen();
});

test.afterAll(async () => {
    await server?.close();
    await rm(fixtureRoot, { recursive: true, force: true });
});

test('panel and navigation edits update without reloading the host', async ({ page }) => {
    await page.goto('http://127.0.0.1:4191');
    await expect(page.getByText('First panel version')).toBeVisible();
    await expect(page.locator('[data-navigation]')).toHaveText('First navigation version');
    await expect(page.locator('body')).toHaveAttribute('data-loads', '1');

    await writeFile(panelSlot, panelSource('Updated panel version'));

    await expect(page.getByText('Updated panel version')).toBeVisible();
    await expect(page.locator('body')).toHaveAttribute('data-loads', '1');

    await writeFile(navigation, navigationSource('Updated navigation version'));

    await expect(page.locator('[data-navigation]')).toHaveText('Updated navigation version');
    await expect(page.locator('body')).toHaveAttribute('data-loads', '1');
});

function panelSource(label: string): string {
    return `export default function Sidebar() { return <nav>${label}</nav>; }\n`;
}

function navigationSource(label: string): string {
    return `
        import { defineNavigation, navigationItem } from '@frolax/bedrock/module';
        export default defineNavigation({
            items: [navigationItem({ key: 'dashboard', label: '${label}', href: '/' })],
        });
    `;
}
