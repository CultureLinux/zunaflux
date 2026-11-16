const { defineConfig } = require('@playwright/test');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const databaseURL = process.env.BROWSER_TEST_DATABASE_URL;
if (!databaseURL) throw new Error('BROWSER_TEST_DATABASE_URL must identify a dedicated browser-test database.');

module.exports = defineConfig({
    testDir: __dirname,
    testMatch: '**/*.spec.cjs',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    forbidOnly: !!process.env.CI,
    timeout: 30_000,
    globalTimeout: 300_000,
    expect: { timeout: 7_000 },
    outputDir: path.join(root, 'var/browser-results'),
    reporter: [
        ['list'],
        ['html', { outputFolder: path.join(root, 'var/browser-report'), open: 'never' }],
        ['junit', { outputFile: path.join(root, 'var/browser-results/results.xml') }],
    ],
    use: {
        baseURL: 'http://127.0.0.1:8175',
        browserName: 'chromium',
        locale: 'fr-FR',
        timezoneId: 'Europe/Paris',
        serviceWorkers: 'block',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'off',
        navigationTimeout: 15_000,
    },
    projects: [
        { name: 'desktop-chromium', use: { viewport: { width: 1440, height: 900 } } },
        { name: 'mobile-chromium', use: { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true } },
    ],
    webServer: {
        command: 'sh tests/Browser/start-server.sh',
        cwd: root,
        url: 'http://127.0.0.1:8175/login',
        reuseExistingServer: false,
        timeout: 60_000,
        env: { APP_ENV: 'browser', APP_DEBUG: '0', DATABASE_URL: databaseURL, BROWSER_TEST_DATABASE_URL: databaseURL },
    },
});
