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
            'resources/frontend/**',
            'resources/stubs/**',
            'resources/boost/**',
            'tests/Fixtures/**',
        ],
    },
    lint: {
        ...bedrockToolchain.lint,
        ignorePatterns: [
            ...(bedrockToolchain.lint?.ignorePatterns ?? []),
            'resources/frontend/**',
            'resources/stubs/**',
        ],
    },
    defaultPackage: './workbench',
});
