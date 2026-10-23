import { IconBox, IconRefresh } from '@tabler/icons-react';
import {
    defineBedrockModule,
    defineNavigation,
    navigationAction,
    navigationItem,
} from '@frolax/bedrock/module';
import type { ComponentType } from 'react';

const frontend = defineBedrockModule({
    pages: import.meta.glob<{ default: ComponentType<Record<string, unknown>> }>(
        './pages/**/*.tsx',
    ),
    navigation: defineNavigation({
        groups: [{ key: 'examples', label: 'Examples', order: 100 }],
        items: [
            navigationItem({
                key: 'example',
                label: 'Example',
                route: 'example.index',
                group: 'examples',
                icon: IconBox,
                permission: 'view',
                active: ['example.*'],
                async resolve({ signal }) {
                    await cancellableDelay(25, signal);
                    return { badge: 'Live' };
                },
                children: [
                    navigationItem({
                        key: 'example-history',
                        label: 'History',
                        route: 'example.history',
                        active: ['example.history'],
                    }),
                ],
            }),
            navigationAction({
                key: 'example-refresh',
                label: 'Refresh example',
                group: 'examples',
                icon: IconRefresh,
                permission: 'view',
                async run({ signal, notify }) {
                    await cancellableDelay(50, signal);
                    notify.success('Example refreshed.');
                },
            }),
        ],
    }),
});

export const {
    resolvePage,
    describeNavigation,
    materializeNavigation,
    resolveNavigation,
    runNavigationAction,
} = frontend;

function cancellableDelay(milliseconds: number, signal: AbortSignal): Promise<void> {
    return new Promise((resolve, reject) => {
        const timer = setTimeout(resolve, milliseconds);
        signal.addEventListener(
            'abort',
            () => {
                clearTimeout(timer);
                reject(signal.reason);
            },
            { once: true },
        );
    });
}
