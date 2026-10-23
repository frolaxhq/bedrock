import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { mergeConfig, type PluginOption, type UserConfig } from 'vite';
import { defineConfig } from 'vite-plus';

import { bedrockHost, bedrockModule } from './plugin.js';

export { bedrockHost, bedrockModule };

/** Shared Vite+ checks for Bedrock hosts and standalone modules. */
export const bedrockToolchain = defineConfig({
    fmt: {
        singleQuote: true,
        semi: true,
        tabWidth: 4,
        ignorePatterns: [
            'node_modules/**',
            'dist/**',
            'public/build/**',
            'storage/**',
            'vendor/**',
        ],
    },
    lint: {
        jsPlugins: [{ name: 'vite-plus', specifier: 'vite-plus/oxlint-plugin' }],
        rules: { 'vite-plus/prefer-vite-plus-imports': 'error' },
        options: { typeAware: true, typeCheck: true },
        ignorePatterns: [
            'node_modules/**',
            'dist/**',
            'public/build/**',
            'storage/**',
            'vendor/**',
        ],
    },
});

/** Merge Bedrock checks with an existing host config while retaining explicit overrides. */
export function mergeBedrockToolchain(config: UserConfig) {
    return mergeConfig(bedrockToolchain, config);
}

interface ModuleConfigOptions {
    key: string;
    entry?: string;
    css?: string;
    hostRoot?: string;
    plugins?: PluginOption[];
    vite?: UserConfig;
}

/** Compose Bedrock's frontend module defaults with explicit module overrides. */
export function defineModuleConfig(options: ModuleConfigOptions) {
    return defineConfig(({ isSsrBuild }) =>
        mergeConfig(
            {
                ...bedrockToolchain,
                ...bedrockModule({
                    key: options.key,
                    entry: options.entry ?? 'resources/js/pages.ts',
                    css: options.css ?? 'resources/css/module.css',
                    hostRoot: options.hostRoot,
                    ssr: Boolean(isSsrBuild),
                    plugins: [react(), tailwindcss(), ...(options.plugins ?? [])],
                }),
            },
            options.vite ?? {},
        ),
    );
}

/** Apply Bedrock's checks, React/Tailwind transforms, and federation to a Laravel Vite+ host. */
export function defineHostConfig(config: UserConfig) {
    const merged = mergeBedrockToolchain(config);

    return defineConfig({
        ...merged,
        plugins: [...(merged.plugins ?? []), react(), tailwindcss(), bedrockHost()],
    });
}
