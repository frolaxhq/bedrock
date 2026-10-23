import { describe, expect, it, vi } from 'vite-plus/test';
import {
    defineBedrockModule,
    defineNavigation,
    navigationAction,
    navigationItem,
} from '../../src/module/index.js';

describe('defineBedrockModule', () => {
    it('resolves a declared page', async () => {
        const page = { default: vi.fn() };
        const module = defineBedrockModule({ pages: { './pages/Index.tsx': async () => page } });

        await expect(module.resolvePage('Index')).resolves.toBe(page);
    });

    it('rejects an unknown page', async () => {
        const module = defineBedrockModule({ pages: {} });

        await expect(module.resolvePage('../Missing')).rejects.toThrow('was not found');
    });

    it('describes static security metadata without running resolvers or actions', async () => {
        const resolve = vi.fn();
        const run = vi.fn();
        const module = defineBedrockModule({
            pages: {},
            navigation: defineNavigation({
                groups: [{ key: 'commerce', label: 'Commerce', order: 20 }],
                items: [
                    navigationItem({
                        key: 'billing',
                        label: 'Billing',
                        route: 'billing.index',
                        group: 'commerce',
                        permission: 'view',
                        panels: ['admin'],
                        active: ['billing.*'],
                        resolve,
                        children: [
                            navigationItem({
                                key: 'invoices',
                                label: 'Invoices',
                                route: 'billing.invoices',
                            }),
                        ],
                    }),
                    navigationAction({ key: 'sync', label: 'Sync', run }),
                ],
            }),
        });

        await expect(module.describeNavigation()).resolves.toEqual({
            groups: [{ key: 'commerce', label: 'Commerce', order: 20 }],
            items: [
                expect.objectContaining({
                    key: 'billing',
                    kind: 'link',
                    route: 'billing.index',
                    permission: 'view',
                    panels: ['admin'],
                    children: [
                        expect.objectContaining({
                            key: 'invoices',
                            parent: 'billing',
                            permission: 'view',
                            panels: ['admin'],
                            group: 'commerce',
                        }),
                    ],
                }),
                expect.objectContaining({ key: 'sync', kind: 'action' }),
            ],
        });
        expect(resolve).not.toHaveBeenCalled();
        expect(run).not.toHaveBeenCalled();
    });

    it('resolves async presentation and runs promise-aware actions', async () => {
        const run = vi.fn().mockResolvedValue(undefined);
        const module = defineBedrockModule({
            pages: {},
            navigation: defineNavigation(async () => ({
                items: [
                    navigationItem({
                        key: 'billing',
                        label: 'Billing',
                        href: '/billing',
                        async resolve() {
                            return { badge: 4, visible: true };
                        },
                    }),
                    navigationAction({ key: 'sync', label: 'Sync', run }),
                ],
            })),
        });
        const context = {
            side: 'module' as const,
            module: 'billing',
            page: { props: {} },
            viewer: null,
            panel: 'default',
            signal: new AbortController().signal,
            can: () => true,
            notify: { success: vi.fn(), error: vi.fn() },
        };

        const resolved = await module.resolveNavigation(context);
        expect(resolved.items[0]).toMatchObject({ badge: 4, visible: true });
        await module.runNavigationAction('sync', context);
        expect(run).toHaveBeenCalledOnce();
    });

    it('rejects unsafe keys, duplicate entries and deeper nesting', async () => {
        const invalid = defineBedrockModule({
            pages: {},
            navigation: defineNavigation({
                items: [navigationItem({ key: 'BadKey', label: 'Bad', href: '/' })],
            }),
        });

        await expect(invalid.describeNavigation()).rejects.toThrow('lowercase kebab-case');
    });
});
