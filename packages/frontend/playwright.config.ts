import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './e2e',
    testMatch: '*.spec.ts',
    outputDir: './e2e/.artifacts/test-results',
    fullyParallel: false,
    reporter: 'line',
    use: {
        baseURL: 'http://127.0.0.1:4179',
        channel: 'chrome',
        trace: 'retain-on-failure',
    },
    webServer: {
        command: 'node e2e/server.mjs',
        port: 4179,
        reuseExistingServer: false,
    },
});
