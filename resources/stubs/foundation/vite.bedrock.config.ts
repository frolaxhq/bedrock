import { defineHostConfig } from '@frolax/bedrock/vite';
import laravel from 'laravel-vite-plugin';

export default defineHostConfig({
    plugins: [
        laravel({
            input: ['resources/css/bedrock.css', 'resources/js/bedrock.tsx'],
            refresh: true,
        }),
    ],
});
