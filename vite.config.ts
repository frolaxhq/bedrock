import { defineConfig } from 'vite-plus';
import { bedrockToolchain } from './packages/frontend/src/vite/index.js';

export default defineConfig({
    ...bedrockToolchain,
    fmt: {
        ...bedrockToolchain.fmt,
        ignorePatterns: [
            ...(bedrockToolchain.fmt?.ignorePatterns ?? []),
            '.agents/**',
            '.github/**',
            '.zcode/**',
            'README.md',
            'composer.json',
            'ide.json',
            'packages/frontend/src/ui/generated.tsx',
            'packages/php/frontend/resources/frontend/**',
            'resources/boost/**',
            'tests/Fixtures/**',
        ],
    },
    lint: {
        ...bedrockToolchain.lint,
        ignorePatterns: [
            ...(bedrockToolchain.lint?.ignorePatterns ?? []),
            'packages/php/frontend/resources/frontend/**',
        ],
    },
    defaultPackage: './workbench',
});
