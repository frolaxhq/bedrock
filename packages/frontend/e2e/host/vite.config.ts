import react from '@vitejs/plugin-react';
import { bedrockHost } from '../../src/vite/index.js';
import { defineConfig } from 'vite';

export default defineConfig({
    base: '/assets/',
    plugins: [react(), bedrockHost({ root: new URL('.', import.meta.url).pathname })],
    build: {
        outDir: 'e2e/.artifacts/host',
        emptyOutDir: true,
        target: 'es2022',
        rollupOptions: {
            input: new URL('./client.tsx', import.meta.url).pathname,
            output: {
                entryFileNames: 'app.js',
                chunkFileNames: 'chunks/[name]-[hash].js',
            },
        },
    },
});
