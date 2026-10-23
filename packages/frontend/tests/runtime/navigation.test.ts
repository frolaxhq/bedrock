import { createElement } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import { defineNavigation, navigationAction, navigationItem } from '../../src/module/index.js';
import type { NavigationResolution } from '../../src/module/index.js';
import type { NavigationContext, NavigationRuntime } from '../../src/runtime/navigation.js';
import {
    prepareNavigation,
    registerHostNavigation,
    resetNavigationStore,
    runNavigationAction,
} from '../../src/runtime/navigation.js';
import type { BedrockNavigationItem, BedrockPage, BedrockViewer } from '../../src/runtime/types.js';

const FakeIcon = (props: Record<string, unknown>) => createElement('i', props);

function envelope(overrides: Partial<BedrockNavigationItem> = {}): BedrockNavigationItem {
    return {
        module: 'billing',
        key: 'billing',
        kind: 'link',
        label: 'Billing',
        route: 'billing.index',
        href: '/billing',
        group: 'commerce',
        order: 10,
        permission: 'billing.view',
        panels: [],
        active: false,
        parent: null,
        children: [],
        ...overrides,
    };
}

function page(options: {
    viewer?: Partial<BedrockViewer> | null;
    navigation?: BedrockNavigationItem[];
    resolved?: NavigationResolution;
    url?: string;
}): BedrockPage {
    return {
        component: 'Dashboard',
        url: options.url ?? '/dashboard',
        props: {
            __bedrock: {
                schema: 1,
                viewer: (options.viewer ?? null) as BedrockViewer | null,
                modules: {},
                ...(options.navigation ? { navigation: options.navigation } : {}),
                ...(options.resolved ? { resolvedNavigation: options.resolved } : {}),
            },
        },
    };
}

function runtime(overrides: Partial<NavigationRuntime> = {}): NavigationRuntime {
    return {
        resolveNavigation: vi.fn().mockResolvedValue({ groups: [], items: [] }),
        runNavigationAction: vi.fn().mockResolvedValue(undefined),
        materializeNavigation: vi.fn().mockResolvedValue({ groups: [], items: [] }),
        ...overrides,
    };
}

async function flush(): Promise<void> {
    await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('prepareNavigation', () => {
    beforeEach(() => {
        resetNavigationStore();
    });

    it('publishes an empty resolution without shared Bedrock props', async () => {
        const resolution = await prepareNavigation({ props: {} } as BedrockPage, vi.fn());

        expect(resolution).toEqual({ groups: [], items: [] });
    });

    it('merges host and module resolutions, filtering the host by panel and permission', async () => {
        registerHostNavigation(
            defineNavigation({
                items: [
                    navigationItem({
                        key: 'dashboard',
                        label: 'Dashboard',
                        href: '/',
                        panels: ['default', 'staff'],
                    }),
                    navigationItem({
                        key: 'secrets',
                        label: 'Secrets',
                        href: '/secrets',
                        permission: 'view-secrets',
                    }),
                ],
            }),
        );
        const moduleRuntime = runtime({
            resolveNavigation: vi.fn().mockResolvedValue({
                groups: [{ key: 'commerce', label: 'Commerce', order: 20 }],
                items: [
                    {
                        ...navigationItem({
                            key: 'billing',
                            label: 'Billing',
                            route: 'billing.index',
                            icon: FakeIcon,
                        }),
                        badge: '3',
                        active: false,
                        visible: true,
                        disabled: false,
                        loading: false,
                        children: [],
                    },
                ],
            }),
        });
        const shared = page({
            viewer: { panel: 'staff', permissions: [] },
            navigation: [envelope()],
        });

        const resolution = await prepareNavigation(
            shared,
            vi.fn().mockResolvedValue(moduleRuntime),
        );

        expect(resolution.items.map((item) => item.key)).toEqual(['dashboard', 'billing']);
        expect(resolution.groups).toEqual([]);
        expect(resolution.items[1]!.badge).toBe('3');
        expect(resolution.items[1]!.icon).toBe(FakeIcon);
        expect(shared.props.__bedrock!.resolvedNavigation!.items[1]!.icon).toBeUndefined();
        expect(shared.props.__bedrock!.runtimeNavigation!.items[1]!.icon).toBe(FakeIcon);
        expect(JSON.stringify(shared.props.__bedrock)).not.toContain('runtimeNavigation');
        expect(moduleRuntime.resolveNavigation).toHaveBeenCalledOnce();
    });

    it('enforces the PHP envelope so the runtime cannot add routes, permissions or items', async () => {
        const moduleRuntime = runtime({
            resolveNavigation: vi.fn().mockResolvedValue({
                groups: [],
                items: [
                    navigationItem({
                        key: 'billing',
                        label: 'Patched label',
                        route: 'billing.admin',
                        permission: 'billing.admin',
                    }),
                    navigationItem({
                        key: 'smuggled',
                        label: 'Smuggled',
                        route: 'billing.smuggled',
                    }),
                ],
            }),
        });
        const shared = page({
            viewer: { panel: 'admin', permissions: ['billing.view'] },
            navigation: [envelope()],
        });

        const resolution = await prepareNavigation(
            shared,
            vi.fn().mockResolvedValue(moduleRuntime),
        );

        expect(resolution.items).toHaveLength(1);
        expect(resolution.items[0]!.key).toBe('billing');
        expect(resolution.items[0]!.route).toBe('billing.index');
        expect(resolution.items[0]!.permission).toBe('billing.view');
        expect(resolution.items[0]!.label).toBe('Patched label');
        expect(resolution.items[0]!.active).toBe(false);
    });

    it('keeps the authorized static envelope when a resolver fails or times out', async () => {
        const moduleRuntime = runtime({
            resolveNavigation: vi
                .fn()
                .mockImplementation(
                    () =>
                        new Promise((resolve) =>
                            setTimeout(() => resolve({ groups: [], items: [] }), 100),
                        ),
                ),
        });
        const shared = page({
            viewer: { panel: 'admin', permissions: ['billing.view'] },
            navigation: [envelope({ active: true })],
        });

        const resolution = await prepareNavigation(
            shared,
            vi.fn().mockResolvedValue(moduleRuntime),
            { timeout: 10 },
        );

        expect(resolution.items).toHaveLength(1);
        expect(resolution.items[0]!.label).toBe('Billing');
        expect(resolution.items[0]!.active).toBe(true);
        expect(resolution.groups).toEqual([]);
    });

    it('surfaces resolver failures in strict mode', async () => {
        const moduleRuntime = runtime({
            resolveNavigation: vi.fn().mockRejectedValue(new Error('resolver exploded')),
        });
        const shared = page({
            viewer: { panel: 'admin', permissions: [] },
            navigation: [envelope()],
        });

        await expect(
            prepareNavigation(shared, vi.fn().mockResolvedValue(moduleRuntime), { strict: true }),
        ).rejects.toThrow('resolver exploded');
    });

    it('aborts a pending resolution when a newer navigation starts', async () => {
        let firstContext: NavigationContext | undefined;
        const slow = runtime({
            resolveNavigation: vi.fn().mockImplementation((context: NavigationContext) => {
                firstContext = context;

                return new Promise(() => []);
            }),
        });
        const shared = page({
            viewer: { panel: 'admin', permissions: [] },
            navigation: [envelope()],
        });
        const loadModule = vi.fn().mockResolvedValue(slow);

        const first = prepareNavigation(shared, loadModule, { timeout: 5_000 });
        await flush();
        await prepareNavigation(
            page({ viewer: { panel: 'admin', permissions: [] } }),
            vi.fn().mockResolvedValue(runtime()),
        );

        expect(firstContext!.signal.aborted).toBe(true);
        await expect(first).rejects.toThrow();
    });

    it('reuses the SSR snapshot without running resolvers again and rebinds icons and actions', async () => {
        registerHostNavigation(
            defineNavigation({
                items: [
                    navigationItem({
                        key: 'dashboard',
                        label: 'Dashboard',
                        href: '/',
                        icon: FakeIcon,
                    }),
                ],
            }),
        );
        const moduleRuntime = runtime({
            resolveNavigation: vi.fn(),
            materializeNavigation: vi.fn().mockResolvedValue({
                groups: [],
                items: [
                    navigationItem({
                        key: 'billing',
                        label: 'Billing',
                        route: 'billing.index',
                        icon: FakeIcon,
                    }),
                    navigationAction({ key: 'sync', label: 'Sync', run: vi.fn() }),
                ],
            }),
        });
        const snapshot: NavigationResolution = {
            groups: [{ key: 'commerce', label: 'Commerce', order: 20 }],
            items: [
                {
                    module: 'billing',
                    key: 'billing',
                    kind: 'link',
                    label: 'Billing',
                    route: 'billing.index',
                    href: '/billing',
                    panels: [],
                    active: true,
                    order: 10,
                    badge: 'Live',
                    visible: true,
                    disabled: false,
                    loading: false,
                    children: [],
                },
                {
                    module: 'billing',
                    key: 'sync',
                    kind: 'action',
                    label: 'Sync',
                    panels: [],
                    active: false,
                    order: 11,
                    visible: true,
                    disabled: false,
                    loading: false,
                    children: [],
                },
            ],
        };
        const shared = page({
            viewer: { panel: 'admin', permissions: ['billing.view'] },
            resolved: snapshot,
        });

        const resolution = await prepareNavigation(
            shared,
            vi.fn().mockResolvedValue(moduleRuntime),
        );

        expect(moduleRuntime.resolveNavigation).not.toHaveBeenCalled();
        expect(resolution.items.find((item) => item.key === 'billing')!.icon).toBe(FakeIcon);
        expect(resolution.items.find((item) => item.key === 'billing')!.badge).toBe('Live');
        expect(resolution.items.find((item) => item.key === 'dashboard')!.icon).toBe(FakeIcon);

        await runNavigationAction('billing:sync');
        expect(moduleRuntime.runNavigationAction).toHaveBeenCalledWith('sync', expect.anything());
    });

    it('replaces the whole action set on each preparation', async () => {
        const firstRuntime = runtime({
            resolveNavigation: vi.fn().mockResolvedValue({
                groups: [],
                items: [navigationAction({ key: 'flush', label: 'Flush', run: vi.fn() })],
            }),
        });
        const secondRuntime = runtime({
            resolveNavigation: vi.fn().mockResolvedValue({
                groups: [],
                items: [navigationAction({ key: 'sync', label: 'Sync', run: vi.fn() })],
            }),
        });
        const loadModule = vi
            .fn()
            .mockResolvedValueOnce(firstRuntime)
            .mockResolvedValueOnce(secondRuntime);

        await prepareNavigation(
            page({
                viewer: { panel: 'admin', permissions: [] },
                navigation: [
                    envelope({ key: 'flush', kind: 'action', route: undefined, href: undefined }),
                ],
            }),
            loadModule,
        );
        await prepareNavigation(
            page({
                viewer: { panel: 'admin', permissions: [] },
                navigation: [
                    envelope({ key: 'sync', kind: 'action', route: undefined, href: undefined }),
                ],
            }),
            loadModule,
        );

        await expect(runNavigationAction('billing:flush')).resolves.toBeUndefined();
        expect(firstRuntime.runNavigationAction).not.toHaveBeenCalled();

        await runNavigationAction('billing:sync');
        expect(secondRuntime.runNavigationAction).toHaveBeenCalledWith('sync', expect.anything());
    });

    it('sorts merged items and groups by order then label', async () => {
        registerHostNavigation(
            defineNavigation({
                items: [navigationItem({ key: 'zeta', label: 'Zeta', href: '/', order: 5 })],
            }),
        );
        const moduleRuntime = runtime({
            resolveNavigation: vi.fn().mockResolvedValue({
                groups: [
                    { key: 'beta-group', label: 'Beta group', order: 2 },
                    { key: 'alpha-group', label: 'Alpha group', order: 2 },
                ],
                items: [
                    navigationItem({ key: 'beta', label: 'Beta', route: 'beta.index', order: 1 }),
                ],
            }),
        });
        const shared = page({
            viewer: { panel: 'admin', permissions: [] },
            navigation: [envelope({ key: 'alpha', label: 'Alpha', order: 1, group: null })],
        });

        const resolution = await prepareNavigation(
            shared,
            vi.fn().mockResolvedValue(moduleRuntime),
        );

        expect(resolution.items.map((item) => item.key)).toEqual(['alpha', 'zeta']);
        expect(resolution.groups).toEqual([]);
    });

    it('notifies through the configured notifier when an action fails', async () => {
        const error = vi.fn();
        const moduleRuntime = runtime({
            runNavigationAction: vi.fn().mockRejectedValue(new Error('sync failed')),
        });
        const shared = page({
            viewer: { panel: 'admin', permissions: [] },
            navigation: [
                envelope({ key: 'flush', kind: 'action', route: undefined, href: undefined }),
            ],
        });

        await prepareNavigation(shared, vi.fn().mockResolvedValue(moduleRuntime), {
            notify: { error },
        });

        await expect(runNavigationAction('billing:flush')).rejects.toThrow('sync failed');
        expect(error).toHaveBeenCalledWith('sync failed');
    });
});
