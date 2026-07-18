import { defineConfig, devices } from '@playwright/test'

const baseURL = process.env.E2E_BASE_URL || 'http://127.0.0.1:8011'

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    retries: 0,
    reporter: 'list',
    use: {
        baseURL,
        headless: true,
        trace: 'on-first-retry',
    },
    globalSetup: './tests/e2e/support/global-setup.js',
    globalTeardown: './tests/e2e/support/global-teardown.js',
    webServer: {
        command: 'php artisan serve --env=testing --host=127.0.0.1 --port=8011',
        url: baseURL,
        reuseExistingServer: false,
        timeout: 120000,
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
})
