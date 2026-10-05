import { defineConfig } from 'vitest/config';
import path from 'node:path';

export default defineConfig({
    resolve: {
        alias: {
            '@bedrock/components': path.resolve(import.meta.dirname, 'src/defaults/components'),
            '@bedrock/hooks': path.resolve(import.meta.dirname, 'src/defaults/hooks'),
            '@bedrock/lib': path.resolve(import.meta.dirname, 'src/ui/lib.ts'),
            '@bedrock/panel': path.resolve(import.meta.dirname, 'src/ui/panel/index.tsx'),
            '@bedrock/react': path.resolve(import.meta.dirname, 'src/runtime/types.ts'),
            '@frolax/bedrock/ui': path.resolve(import.meta.dirname, 'src/ui/index.tsx'),
            '@frolax/bedrock/theme': path.resolve(import.meta.dirname, 'src/ui/theme/index.tsx'),
        },
    },
    test: {
        environment: 'jsdom',
        include: ['tests/**/*.test.{ts,tsx}'],
        restoreMocks: true,
        setupFiles: ['./tests/setup.ts'],
    },
});
