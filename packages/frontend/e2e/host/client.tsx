/// <reference path="../../src/runtime/virtual.d.ts" />
import { createInertiaApp, Head, Link } from '@inertiajs/react';
import { AppLayout } from '@frolax/bedrock/ui';
import { ThemeProvider } from '@frolax/bedrock/theme';
import { createElement } from 'react';
import { hydrateRoot } from 'react-dom/client';
import { createBedrockResolver } from '../../src/runtime/react.js';
import 'virtual:bedrock-overrides';

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

const resolve = createBedrockResolver({
    local: async (name) => {
        if (name !== 'Home') throw new Error(`Host page [${name}] was not found.`);

        return { default: Home };
    },
    versions: {
        react: '19.3.0',
        'react-dom': '19.3.0',
        '@inertiajs/react': '3.8.0',
        '@frolax/bedrock': '1.0.0',
    },
});

void createInertiaApp({
    resolve,
    setup({ el, App, props }) {
        hydrateRoot(
            el,
            createElement(ThemeProvider, { direction: 'rtl' }, createElement(App, props)),
        );
        el.dataset.hydrated = 'true';
    },
});
