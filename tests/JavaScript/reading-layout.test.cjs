const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { evaluateArticleList } = require('../Support/evaluate-article-list.cjs');
test('article actions come before the article title', () => {
    const template = fs.readFileSync('templates/article/index.html.twig', 'utf8');
    const dialog = template.split('data-article-dialog')[1];
    assert.ok(dialog.indexOf("include 'article/_actions.html.twig'") < dialog.indexOf('data-article-title'));
});

test('the search header contains the read filter before the search input', () => {
    const template = fs.readFileSync('templates/article/index.html.twig', 'utf8');
    const header = template.match(/<header class="reading-search-header">([\s\S]*?)<\/header>/)?.[1] || '';
    assert.ok(header.includes('data-read-filter'));
    assert.ok(header.indexOf('data-read-filter') < header.indexOf('data-article-search'));
    assert.equal((template.match(/data-read-filter/g) || []).length, 1);
});

test('the empty filtered state uses the translated label from the article list', () => {
    const template = fs.readFileSync('templates/article/index.html.twig', 'utf8');
    const script = fs.readFileSync('assets/controllers/article-list.js', 'utf8');

    assert.match(template, /data-empty-filtered-label=/);
    assert.match(script, /container\.dataset\.emptyFilteredLabel/);
    assert.doesNotMatch(script, /Aucun article ne correspond à vos critères\./);
    // The screen-reader status must not duplicate the visible empty message (it broke getByText in the browser suite).
    assert.doesNotMatch(script, /status\.textContent[^\n]*emptyFilteredLabel/);
});

test('article readers expose a native-share control with the loaded article URL', async () => {
    const template = fs.readFileSync('templates/article/_actions.html.twig', 'utf8');
    const zenTemplate = fs.readFileSync('templates/article/index.html.twig', 'utf8');
    const script = fs.readFileSync('assets/controllers/article-list.js', 'utf8');
    const calls = [];
    const context = {
        document: { querySelector: () => null },
        navigator: { share: async (data) => { calls.push(data); } },
        URL,
    };

    evaluateArticleList(context, '\nthis.shareArticle = shareArticle;');
    await context.shareArticle({ title: 'Une lecture', url: 'https://example.test/article' });

    assert.match(template, /data-article-share/);
    assert.match(zenTemplate, /data-zen-share/);
    assert.equal(calls.length, 1);
    assert.equal(calls[0].title, 'Une lecture');
    assert.equal(calls[0].url, 'https://example.test/article');
});

test('article sharing copies the article URL when native sharing is unavailable', async () => {
    const script = fs.readFileSync('assets/controllers/article-list.js', 'utf8');
    const copied = [];
    const context = {
        document: { querySelector: () => null },
        navigator: { clipboard: { writeText: async (url) => { copied.push(url); } } },
        URL,
    };

    evaluateArticleList(context, '\nthis.shareArticle = shareArticle;');

    assert.equal(await context.shareArticle({ title: 'Une lecture', url: 'https://example.test/article' }), 'copied');
    assert.deepEqual(copied, ['https://example.test/article']);
});

test('copying an article link announces success through the shared toast system', () => {
    const script = fs.readFileSync('assets/controllers/article-list.js', 'utf8');
    const template = fs.readFileSync('templates/article/index.html.twig', 'utf8');

    assert.match(script, /new CustomEvent\('zunaflux:toast'/);
    assert.doesNotMatch(template, /data-article-share-status/);
});

test('share controls stay visible and the modal article uses its available width', () => {
    const actions = fs.readFileSync('templates/article/_actions.html.twig', 'utf8');
    const template = fs.readFileSync('templates/article/index.html.twig', 'utf8');

    assert.doesNotMatch(actions, /data-article-share[^>]*\shidden/);
    assert.doesNotMatch(template, /data-zen-share[^>]*\shidden/);
    assert.doesNotMatch(template, /data-article-summary[^>]*max-w-prose|max-w-prose[^>]*data-article-summary/);
});

test('the desktop source list keeps only sources from the selected category', () => {
    const context = { document: { querySelector: () => null } };
    context.sourceIsVisible = require('../../assets/reading/sources.js').isReadingSourceVisible;

    assert.equal(context.sourceIsVisible({ unread: 2, read: 1, favorite: 1, saved: 0 }, 'favorite', 'science', 'science'), true);
    assert.equal(context.sourceIsVisible({ unread: 2, read: 1, favorite: 0, saved: 1 }, 'favorite', 'science', 'science'), true);
    assert.equal(context.sourceIsVisible({ unread: 2, read: 1, favorite: 1, saved: 0 }, 'saved', 'culture', 'science'), false);
    assert.equal(context.sourceIsVisible({ unread: 2, read: 1, favorite: 1, saved: 0 }, 'saved', 'science', 'science'), true);
    assert.equal(context.sourceIsVisible({ unread: 0, read: 3, favorite: 1, saved: 1 }, 'favorite', 'science', 'science'), false);
    assert.equal(context.sourceIsVisible({ unread: 0, read: 0 }, '', 'science', 'science'), false);
});

test('the desktop source pane exposes the active category context', () => {
    const template = fs.readFileSync('templates/article/index.html.twig', 'utf8');
    assert.match(template, /data-reading-category-context/);
});
