import '../css/bedrock.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { ThemeProvider } from '@frolax/bedrock/theme';
import { createBedrockResolver } from '@frolax/bedrock/react';
import { resolveFoundationPage } from '@frolax/bedrock/pages';
import type { ResolvedPage } from '@frolax/bedrock/react';
import 'virtual:bedrock-overrides';

const pages = import.meta.glob<ResolvedPage>('./pages/**/*.tsx');
void createInertiaApp({
    resolve: createBedrockResolver({
        local(name) {
            const page = pages[`./pages/${name}.tsx`];
            return page ? page() : resolveFoundationPage(name);
        },
    }),
    setup({ el, App, props }) {
        createRoot(el).render(
            <ThemeProvider>
                <App {...props} />
            </ThemeProvider>,
        );
    },
});
