const { test: base, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
function fixtures(operation, token) {
    const output = execFileSync('php85', ['tests/Browser/fixtures.php', operation, token], {
        cwd: root,
        env: { ...process.env, APP_ENV: 'browser', APP_DEBUG: '0', DATABASE_URL: process.env.BROWSER_TEST_DATABASE_URL },
        encoding: 'utf8',
        timeout: 20_000,
        stdio: ['ignore', 'pipe', 'pipe'],
    });
    return JSON.parse(output);
}

async function login(page, email, password) {
    await page.goto('/login');
    await page.getByLabel('Adresse e-mail', { exact: true }).fill(email);
    await page.getByLabel('Mot de passe', { exact: true }).fill(password);
    await Promise.all([
        page.waitForURL((url) => url.pathname !== '/login'),
        page.getByRole('button', { name: 'Se connecter', exact: true }).click(),
    ]);
    await page.goto('/articles');
}

const test = base.extend({
    scenario: async ({ page }, use) => {
        const token = randomBytes(16).toString('hex');
        try {
            const data = fixtures('seed', token);
            await login(page, data.email, data.password);
            await expect(page.locator('[data-article-row-id]')).toHaveCount(3);
            await use({ page, data });
        } finally {
            await page.close();
            fixtures('cleanup', token);
        }
    },
});

function opener(page, title) {
    return page.locator('[data-article-list]').getByRole('button').filter({
        has: page.getByText(title, { exact: true }),
    });
}

async function state(page, id) {
    const response = await page.request.get(`/api/articles/${id}`);
    expect(response.status()).toBe(200);
    return response.json();
}

async function tabTo(page, target) {
    await expect(target).toBeVisible();
    for (let step = 0; step < 80; step += 1) {
        if (await target.evaluate((element) => element === document.activeElement)) return;
        await page.keyboard.press('Tab');
    }
    throw new Error('The requested control could not be reached using Tab.');
}

module.exports = { test, expect, login, opener, state, tabTo };
