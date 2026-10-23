import { defineHostConfig } from '@frolax/bedrock/vite';
import laravel from 'laravel-vite-plugin';

export default defineHostConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            ssr: 'resources/js/ssr.tsx',
            refresh: true,
        }),
    ],
});
