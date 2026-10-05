import { loadRemote, registerRemotes } from '@module-federation/enhanced/runtime';
import { pathToFileURL } from 'node:url';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createBedrockResolver } from '../../src/runtime/react.js';
import { defineNavigation, navigationItem } from '../../src/module/index.js';
import { registerHostNavigation, resetNavigationStore } from '../../src/runtime/navigation.js';
import type { BedrockFrontendModule, BedrockPage } from '../../src/runtime/types.js';

vi.mock('@module-federation/enhanced/runtime', () => ({
    loadRemote: vi.fn(),
    registerRemotes: vi.fn(),
}));

const mockedLoadRemote = vi.mocked(loadRemote);
const mockedRegisterRemotes = vi.mocked(registerRemotes);

function remote() {
    return {
        resolvePage: vi.fn().mockResolvedValue({ default: vi.fn() }),
        resolveNavigation: vi.fn().mockResolvedValue({ groups: [], items: [] }),
        runNavigationAction: vi.fn().mockResolvedValue(undefined),
    };
}

function descriptor(overrides: Partial<BedrockFrontendModule> = {}): BedrockFrontendModule {
    return {
        key: 'billing',
        version: '1.0.0',
        driver: 'inertia-react',
        client: '/modules/billing/1.0.0/mf-manifest.json',
        server: pathToFileURL(new URL('../fixtures/server-module.mjs', import.meta.url).pathname).href,
        css: ['/modules/billing/1.0.0/module.css'],
        requires: { react: '^19.0.0' },
        navigation: { groups: [], items: [] },
        development: false,
        ...overrides,
    };
}

function page(module: BedrockFrontendModule): BedrockPage {
    return {
        props: {
            __bedrock: {
                schema: 1,
                modules: { [module.key]: module },
            },
        },
    };
}

describe('createBedrockResolver', () => {
    beforeEach(() => {
        resetNavigationStore();
        document.head.innerHTML = '';
        mockedLoadRemote.mockReset();
        mockedRegisterRemotes.mockReset();
    });

    it('re-resolves conventional host navigation after an HMR registration', async () => {
        const shared = page(descriptor());
        shared.props.__bedrock!.modules = {};
        const resolve = createBedrockResolver({ local: vi.fn().mockResolvedValue({ default: vi.fn() }) });

        await resolve('Dashboard', shared);
        registerHostNavigation(defineNavigation({
            items: [navigationItem({ key: 'reports', label: 'Reports', href: '/reports' })],
        }));
        await new Promise((done) => setTimeout(done, 0));

        expect(shared.props.__bedrock!.resolvedNavigation?.items[0]?.key).toBe('reports');
    });

    it('delegates host pages to the local resolver', async () => {
        const localPage = vi.fn();
        const local = vi.fn().mockResolvedValue({ default: localPage });
        const resolve = createBedrockResolver({ local });

        await expect(resolve('Dashboard', { props: {} })).resolves.toEqual({ default: localPage });
        expect(local).toHaveBeenCalledWith('Dashboard', { props: {} });
    });

    it('validates shared versions before registering a browser remote', async () => {
        const resolve = createBedrockResolver({ local: vi.fn(), versions: { react: '18.3.0' } });

        await expect(resolve('module:billing/Index', page(descriptor()))).rejects.toThrow(
            'requires [react ^19.0.0]',
        );
        expect(mockedRegisterRemotes).not.toHaveBeenCalled();
    });

    it('registers and caches a remote and its stylesheet by key and version', async () => {
        const loaded = remote();
        mockedLoadRemote.mockResolvedValue(loaded);
        const resolve = createBedrockResolver({ local: vi.fn(), versions: { react: '19.3.0' } });
        const shared = page(descriptor({ key: 'orders' }));

        await resolve('module:orders/Index', shared);
        await resolve('module:orders/Create', shared);

        expect(mockedRegisterRemotes).toHaveBeenCalledOnce();
        expect(mockedRegisterRemotes).toHaveBeenCalledWith(
            [expect.objectContaining({ name: 'orders', entry: '/modules/billing/1.0.0/mf-manifest.json' })],
            { force: true },
        );
        expect(mockedLoadRemote).toHaveBeenCalledOnce();
        expect(mockedLoadRemote).toHaveBeenCalledWith('orders/module');
        expect(loaded.resolvePage).toHaveBeenNthCalledWith(1, 'Index');
        expect(loaded.resolvePage).toHaveBeenNthCalledWith(2, 'Create');
        expect(document.querySelectorAll('link[data-bedrock-style]')).toHaveLength(1);
    });

    it('drops failed remote loads so a transient error can be retried', async () => {
        mockedLoadRemote
            .mockRejectedValueOnce(new Error('temporary network failure'))
            .mockResolvedValueOnce(remote());
        const resolve = createBedrockResolver({ local: vi.fn(), versions: { react: '19.3.0' } });
        const shared = page(descriptor({ key: 'retry', version: '1.0.1' }));

        await expect(resolve('module:retry/Index', shared)).rejects.toThrow('temporary network failure');
        await expect(resolve('module:retry/Index', shared)).resolves.toBeDefined();
        expect(mockedLoadRemote).toHaveBeenCalledTimes(2);
    });

    it('loads the protected native ESM entry during SSR', async () => {
        vi.stubGlobal('window', undefined);
        const module = descriptor({ key: 'server', version: '2.0.0' });
        const resolve = createBedrockResolver({ local: vi.fn(), versions: { react: '19.3.0' } });

        const resolved = await resolve('module:server/Index', page(module));

        expect(resolved).toHaveProperty('default');
        expect(mockedRegisterRemotes).not.toHaveBeenCalled();
        vi.unstubAllGlobals();
    });
});
