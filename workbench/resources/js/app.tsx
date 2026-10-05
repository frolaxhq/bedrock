import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import { ThemeProvider } from '@frolax/bedrock/theme';
import { createRoot } from 'react-dom/client';
import { resolveBedrockPage } from './bedrock-pages';

void createInertiaApp({
    resolve: resolveBedrockPage,
    setup({ el, App, props }) {
        createRoot(el).render(
            <ThemeProvider>
                <App {...props} />
            </ThemeProvider>,
        );
    },
});
