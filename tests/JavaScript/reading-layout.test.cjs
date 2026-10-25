const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
test('the saved desktop layout preference controls the split layout without changing mobile dialogs', () => {
    const template = fs.readFileSync('templates/article/index.html.twig', 'utf8');
    const script = fs.readFileSync('assets/controllers/article-list.js', 'utf8');
    const css = fs.readFileSync('assets/styles/app.css', 'utf8');
    assert.match(template, /preference\.sidebarCollapsed.*reading-workspace/);
    assert.match(css, /@media \(min-width: 1024px\)/);
    assert.match(script, /matchMedia\('\(min-width: 1024px\)'\)/);
    assert.match(script, /dialog\.showModal\(\)/);
    assert.match(script, /dialog\.show\(\)/);
});

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

test('the search field provides an accessible clear button', () => {
    const template = fs.readFileSync('templates/article/index.html.twig', 'utf8');
    assert.match(template, /data-clear-search/);
    assert.match(template, /aria-label="{{ 'Effacer la recherche'\|trans }}"/);
});

test('the article list has one column on desktop while keeping the mobile layout', () => {
    const script = fs.readFileSync('assets/controllers/article-list.js', 'utf8');
    const classes = script.match(/list\.className = '([^']+)'/)?.[1].split(' ') || [];
    assert.ok(classes.includes('grid-cols-1'));
    assert.ok(classes.includes('md:grid-cols-2'));
    assert.ok(classes.includes('lg:grid-cols-1'));
    assert.ok(!classes.some(name => /^(xl|2xl):grid-cols-/.test(name)));
});

test('the empty filtered state uses the translated label from the article list', () => {
    const template = fs.readFileSync('templates/article/index.html.twig', 'utf8');
    const script = fs.readFileSync('assets/controllers/article-list.js', 'utf8');

    assert.match(template, /data-empty-filtered-label=/);
    assert.match(script, /container\.dataset\.emptyFilteredLabel/);
    assert.doesNotMatch(script, /Aucun article ne correspond à vos critères\./);
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

    vm.runInNewContext(script + '\nthis.shareArticle = shareArticle;', context);
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

    vm.runInNewContext(script + '\nthis.shareArticle = shareArticle;', context);

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
    vm.runInNewContext(fs.readFileSync('assets/controllers/article-list.js', 'utf8') + '\nthis.sourceIsVisible = isReadingSourceVisible;', context);

    assert.equal(context.sourceIsVisible({ unread: 2, read: 1 }, 'favorite', 'science', 'science'), true);
    assert.equal(context.sourceIsVisible({ unread: 2, read: 1 }, 'saved', 'culture', 'science'), false);
    assert.equal(context.sourceIsVisible({ unread: 0, read: 0 }, '', 'science', 'science'), false);
});

test('the desktop source pane exposes the active category context', () => {
    const template = fs.readFileSync('templates/article/index.html.twig', 'utf8');
    assert.match(template, /data-reading-category-context/);
});
