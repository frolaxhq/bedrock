import { defineConfig } from '@playwright/test';

const consumer = process.env.BEDROCK_CONSUMER_PATH;
if (!consumer) throw new Error('Set BEDROCK_CONSUMER_PATH to a prepared fresh Laravel consumer.');

export default defineConfig({
    testDir: './e2e',
    testMatch: 'foundation.browser.ts',
    outputDir: './e2e/.artifacts/foundation-results',
    fullyParallel: false,
    workers: 1,
    reporter: 'line',
    use: { baseURL: 'http://127.0.0.1:4188', channel: 'chrome', trace: 'retain-on-failure' },
    webServer: {
        command: 'php artisan serve --host=127.0.0.1 --port=4188',
        cwd: consumer,
        port: 4188,
        reuseExistingServer: false,
    },
});
