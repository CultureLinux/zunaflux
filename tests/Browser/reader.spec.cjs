const { test, expect, login, opener, state, tabTo } = require('./support.cjs');

const rows = (page) => page.locator('[data-article-row-id]');
const reader = (page, title) => page.getByRole('dialog', { name: title, exact: true });
const readFilter = (page) => page.getByRole('combobox', { name: 'Vue des articles', exact: true });
const closeReader = (dialog) => dialog.getByRole('button', { name: /Retour à la liste|Fermer/ });

test('recharger les articles charge les modules sans erreur et initialise la liste', async ({ scenario }) => {
    const { page } = scenario;
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    const sourcesResponse = page.waitForResponse((response) => /\/reading\/sources(?:-[^/]+)?\.js$/.test(new URL(response.url()).pathname));
    await page.reload();
    const response = await sourcesResponse;
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toMatch(/(?:text|application)\/javascript/);
    await expect(rows(page)).toHaveCount(3);
    expect(errors).toEqual([]);
});

test('ouvrir et fermer un article dans le lecteur adapté au viewport', async ({ scenario }, testInfo) => {
    const { page, data } = scenario;
    await expect(opener(page, data.articles.private.title)).toHaveCount(0);
    await opener(page, data.articles.alpha.title).click();
    const dialog = reader(page, data.articles.alpha.title);
    await expect(dialog).toBeVisible();
    await expect(dialog.getByText('Contenu détaillé Alpha.', { exact: true })).toBeVisible();
    expect(await dialog.evaluate((element) => element.matches(':modal'))).toBe(testInfo.project.name === 'mobile-chromium');
    await closeReader(dialog).click();
    await expect(dialog).not.toBeVisible();
    await expect(opener(page, data.articles.alpha.title)).toBeVisible();
});

test('marquer lu persiste côté serveur et modifie la vue des articles', async ({ scenario }, testInfo) => {
    const { page, data } = scenario;
    const article = data.articles.alpha;
    await opener(page, article.title).click();
    const dialog = reader(page, article.title);
    await expect(dialog).toBeVisible();
    expect((await state(page, article.id)).isRead).toBe(false);
    await dialog.getByRole('button', { name: 'Marquer lu', exact: true }).first().click();
    await expect.poll(async () => (await state(page, article.id)).isRead).toBe(true);
    if (testInfo.project.name === 'desktop-chromium') await closeReader(dialog).click();
    await page.reload();
    await expect(rows(page)).toHaveCount(2);
    await expect(opener(page, article.title)).toHaveCount(0);
    await readFilter(page).selectOption('1');
    await expect(rows(page)).toHaveCount(2);
    await expect(opener(page, article.title)).toBeVisible();
});

test('filtrer les lus, tous les articles, favoris et une liste vide', async ({ scenario }) => {
    const { page, data } = scenario;
    await readFilter(page).selectOption('1');
    await expect(rows(page)).toHaveCount(1);
    await expect(opener(page, data.articles.read.title)).toBeVisible();
    await readFilter(page).selectOption('');
    await expect(rows(page)).toHaveCount(4);
    await readFilter(page).selectOption('favorite');
    await expect(rows(page)).toHaveCount(1);
    await expect(opener(page, data.articles.alphaSecond.title)).toBeVisible();
    await readFilter(page).selectOption('saved');
    await expect(rows(page)).toHaveCount(0);
    await expect(page.getByText('Aucun article ne correspond à vos critères.', { exact: true })).toBeVisible();
});

test('changer de source puis retirer le filtre', async ({ scenario }) => {
    const { page, data } = scenario;
    await page.getByRole('button', { name: 'Filtrer sur Source Beta', exact: true }).click();
    await expect(rows(page)).toHaveCount(1);
    await expect(opener(page, data.articles.beta.title)).toBeVisible();
    await page.getByRole('button', { name: 'Voir tous les flux', exact: true }).click();
    await expect(rows(page)).toHaveCount(3);
    await page.getByRole('button', { name: 'Filtrer sur Source Alpha', exact: true }).first().click();
    await expect(rows(page)).toHaveCount(2);
    await expect(opener(page, data.articles.alpha.title)).toBeVisible();
    await expect(opener(page, data.articles.beta.title)).toHaveCount(0);
});

test('rechercher puis effacer la recherche restaure la liste', async ({ scenario }) => {
    const { page, data } = scenario;
    await readFilter(page).selectOption('');
    await expect(rows(page)).toHaveCount(4);
    await page.getByRole('searchbox', { name: 'Rechercher dans vos articles', exact: true }).fill('Observabilité');
    await page.getByRole('button', { name: 'Rechercher', exact: true }).click();
    await expect(rows(page)).toHaveCount(1);
    await expect(opener(page, data.articles.alpha.title)).toBeVisible();
    await page.getByRole('button', { name: 'Effacer la recherche', exact: true }).click();
    await expect(rows(page)).toHaveCount(4);
});

test('enregistrer une préférence change réellement le mode de lecture', async ({ scenario }) => {
    const { page, data } = scenario;
    await page.goto('/preferences');
    const mode = page.getByRole('combobox', { name: /^Lecture/ });
    const split = page.getByRole('switch', { name: /^Vue de lecture avec panneau latéral/ });
    await expect(split).toBeChecked();
    await mode.selectOption('on_preview');
    await page.getByText('Vue de lecture avec panneau latéral', { exact: true }).click();
    await expect(split).not.toBeChecked();
    const saved = page.waitForResponse((response) => new URL(response.url()).pathname === '/preferences' && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    expect((await saved).status()).toBeLessThan(400);
    await page.reload();
    await expect(mode).toHaveValue('on_preview');
    await expect(split).not.toBeChecked();
    await page.goto('/articles');
    await opener(page, data.articles.alpha.title).click();
    const dialog = reader(page, data.articles.alpha.title);
    await expect(dialog).toBeVisible();
    expect(await dialog.evaluate((element) => element.matches(':modal'))).toBe(true);
    await expect.poll(async () => (await state(page, data.articles.alpha.id)).isRead).toBe(true);
});

test('naviguer au clavier et conserver les comptes isolés', async ({ scenario, browser }) => {
    const { page, data } = scenario;
    await tabTo(page, opener(page, data.articles.alpha.title));
    await page.keyboard.press('Enter');
    await expect(reader(page, data.articles.alpha.title)).toBeVisible();
    await page.keyboard.press('ArrowRight');
    const next = reader(page, data.articles.alphaSecond.title);
    await expect(next).toBeVisible();
    await tabTo(page, closeReader(next));
    await page.keyboard.press('Enter');
    await expect(next).not.toBeVisible();
    expect([403, 404]).toContain((await page.request.get(`/api/articles/${data.articles.private.id}`)).status());
    const otherContext = await browser.newContext({ baseURL: 'http://127.0.0.1:8175', locale: 'fr-FR', serviceWorkers: 'block' });
    try {
        const otherPage = await otherContext.newPage();
        await login(otherPage, data.otherEmail, data.password);
        await expect(rows(otherPage)).toHaveCount(1);
        await expect(opener(otherPage, data.articles.private.title)).toBeVisible();
        expect([403, 404]).toContain((await otherPage.request.get(`/api/articles/${data.articles.alpha.id}`)).status());
        await page.reload();
        await expect(rows(page)).toHaveCount(3);
        await expect(opener(page, data.articles.private.title)).toHaveCount(0);
    } finally {
        await otherContext.close();
    }
});
