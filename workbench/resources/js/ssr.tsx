import { createInertiaApp } from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import { ThemeProvider } from '@frolax/bedrock/theme';
import { renderToString } from 'react-dom/server';
import { resolveBedrockPage } from './bedrock-pages';

createServer((page) =>
    createInertiaApp({
        page,
        render: renderToString,
        resolve: resolveBedrockPage,
        setup: ({ App, props }) => (
            <ThemeProvider>
                <App {...props} />
            </ThemeProvider>
        ),
    }),
);
