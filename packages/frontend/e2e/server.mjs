import { createInertiaApp, Head, Link } from '@inertiajs/react';
import { IconDashboard } from '@tabler/icons-react';
import { createElement } from 'react';
import { renderToString } from 'react-dom/server';
import { createServer } from 'node:http';
import { extname, resolve } from 'node:path';
import { readFile } from 'node:fs/promises';
import { createBedrockResolver } from '../dist/runtime/react.js';
import { registerHostNavigation } from '../dist/runtime/react.js';
import { defineNavigation, navigationItem } from '../dist/module/index.js';
import { AppLayout } from '../dist/ui/index.js';
import { registerFrontendPanels } from '../dist/ui/runtime.js';
import { ThemeProvider } from '../dist/ui/theme/index.js';

const packageRoot = resolve(import.meta.dirname, '..');
const repositoryRoot = resolve(packageRoot, '../..');
const artifactRoot = resolve(import.meta.dirname, '.artifacts');
const hostOutput = resolve(artifactRoot, 'host');
const moduleRoot = resolve(repositoryRoot, 'workbench/modules/example');
const clientRoot = resolve(moduleRoot, 'dist/bedrock/client');
const serverEntry = resolve(moduleRoot, 'dist/bedrock/server/entry.mjs');

registerFrontendPanels({
    admin: { slots: { 'sidebar-footer': () => createElement('div', null, 'Administrator panel footer') } },
});
registerHostNavigation(defineNavigation({
    items: [navigationItem({
        key: 'dashboard',
        label: 'Dashboard',
        href: '/',
        panels: ['admin'],
        active: ['/'],
        icon: IconDashboard,
    })],
}));

const artifact = JSON.parse(await readFile(resolve(moduleRoot, 'dist/bedrock/frontend.json'), 'utf8'));

function Home() {
    return createElement(
        AppLayout,
        null,
        createElement(
            'main',
            null,
            createElement(Head, { title: 'Host' }),
            createElement('h1', null, 'Host application'),
            createElement(Link, { href: '/example' }, 'Open module'),
        ),
    );
}

const resolvePage = createBedrockResolver({
    local: async (name) => {
        if (name !== 'Home') throw new Error(`Host page [${name}] was not found.`);

        return { default: Home };
    },
    versions: {
        react: '19.3.0',
        'react-dom': '19.3.0',
        '@inertiajs/react': '3.7.1',
        '@frolax/bedrock': '1.0.0',
    },
});

createServer(async (request, response) => {
    try {
        const url = new URL(request.url ?? '/', 'http://127.0.0.1:4179');

        if (url.pathname === '/__build') {
            return json(response, JSON.parse(await readFile(resolve(artifactRoot, 'build-order.json'), 'utf8')));
        }

        if (url.pathname.startsWith('/assets/')) {
            return file(response, resolve(hostOutput, url.pathname.slice('/assets/'.length)));
        }

        if (url.pathname.startsWith('/vendor/bedrock/modules/example/1.0.0/')) {
            return file(response, resolve(clientRoot, url.pathname.split('/1.0.0/')[1]));
        }

        const page = pageFor(url.pathname);

        if (request.headers['x-inertia']) {
            response.setHeader('X-Inertia', 'true');
            response.setHeader('Vary', 'X-Inertia');

            return json(response, page);
        }

        const rendered = await createInertiaApp({
            page,
            resolve: resolvePage,
            render: renderToString,
            setup: ({ App, props }) => createElement(
                ThemeProvider,
                { direction: 'rtl' },
                createElement(App, props),
            ),
        });
        const heads = rendered.head.join('\n');
        const html = `<!doctype html><html dir="rtl"><head>${heads}</head><body>${rendered.body}<script type="module" src="/assets/app.js"></script></body></html>`;
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        response.end(html);
    } catch (error) {
        response.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' });
        response.end(error instanceof Error ? error.stack : String(error));
    }
}).listen(4179, '127.0.0.1');

function pageFor(pathname) {
    const module = pathname !== '/';
    const navigation = moduleNavigation(artifact.navigation.items, pathname);

    return {
        component: module ? 'module:example/Index' : 'Home',
        props: {
            errors: {},
            ...(module ? { message: 'Hello from an independently built Bedrock module.' } : {}),
            __bedrock: {
                schema: 1,
                viewer: {
                    id: 1,
                    name: 'Bedrock Administrator',
                    email: 'admin@example.test',
                    avatar: null,
                    panel: 'admin',
                    permissions: ['example.view'],
                    actions: {},
                },
                modules: {
                    example: {
                        key: 'example',
                        version: '1.0.0',
                        driver: 'inertia-react',
                        client: '/vendor/bedrock/modules/example/1.0.0/mf-manifest.json',
                        server: serverEntry,
                        css: artifact.client.css.map((file) => `/vendor/bedrock/modules/example/1.0.0/${file.replace(/^client\//, '')}`),
                        requires: {
                            react: '^19.0',
                            'react-dom': '^19.0',
                            '@inertiajs/react': '^3.0',
                            '@frolax/bedrock': '^1.0',
                        },
                        navigation: artifact.navigation,
                        development: false,
                    },
                },
                navigation,
            },
        },
        url: module ? '/example' : '/',
        version: 'test',
        rescuedProps: [],
    };
}

function moduleNavigation(items, pathname, parent = null) {
    const routes = {
        'example.index': '/example',
        'example.history': '/example/history',
    };

    return items.map((item) => ({
        module: 'example',
        key: item.key,
        kind: item.kind,
        label: item.label,
        ...(item.route ? { route: item.route, href: routes[item.route] } : {}),
        ...(item.group ? { group: item.group } : {}),
        order: item.order,
        ...(item.permission ? { permission: `example.${item.permission}` } : {}),
        panels: item.panels,
        active: item.route === 'example.index'
            ? pathname.startsWith('/example')
            : item.route === 'example.history' && pathname === '/example/history',
        parent: parent ?? item.parent ?? null,
        children: moduleNavigation(item.children, pathname, item.key),
    }));
}

async function file(response, path) {
    const types = { '.js': 'text/javascript', '.json': 'application/json', '.css': 'text/css' };
    response.writeHead(200, { 'Content-Type': types[extname(path)] ?? 'application/octet-stream' });
    response.end(await readFile(path));
}

function json(response, value) {
    response.writeHead(200, { 'Content-Type': 'application/json', 'X-Inertia': response.getHeader('X-Inertia') ?? '' });
    response.end(JSON.stringify(value));
}
