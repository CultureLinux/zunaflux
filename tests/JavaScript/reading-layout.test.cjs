const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
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

test('the article list has one column on desktop while keeping the mobile layout', () => {
    const script = fs.readFileSync('assets/controllers/article-list.js', 'utf8');
    const classes = script.match(/list\.className = '([^']+)'/)?.[1].split(' ') || [];
    assert.ok(classes.includes('grid-cols-1'));
    assert.ok(classes.includes('md:grid-cols-2'));
    assert.ok(classes.includes('lg:grid-cols-1'));
    assert.ok(!classes.some(name => /^(xl|2xl):grid-cols-/.test(name)));
});
