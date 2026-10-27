const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('zen gestures navigate horizontally and leave vertical scrolling alone', () => {
    const context = { document: { querySelector: () => null } };
    vm.runInNewContext(fs.readFileSync('assets/controllers/zen.js', 'utf8') + '\nthis.classify = classifyZenGesture;', context);
    assert.equal(context.classify(-90, 5), 'next');
    assert.equal(context.classify(90, 5), 'previous');
    assert.equal(context.classify(5, 90), null);
    assert.equal(context.classify(5, -90), null);
    assert.equal(context.classify(15, 10), null);
});

test('zen gestures target article content and remaining count appears in the header', () => {
    const script = fs.readFileSync('assets/controllers/zen.js', 'utf8');
    const template = fs.readFileSync('templates/article/index.html.twig', 'utf8');
    assert.match(script, /content\.addEventListener\('touchstart'/);
    assert.match(script, /content\.addEventListener\('touchend'/);
    assert.doesNotMatch(template, /data-zen-gesture/);
    assert.match(template, /class="zen-header"[^\n]*data-zen-remaining/);
});

test('zen counts use translated singular and plural messages', () => {
    const context = { document: { querySelector: () => null } };
    vm.runInNewContext(fs.readFileSync('assets/controllers/zen.js', 'utf8') + '\nthis.formatCount = formatZenCount;', context);
    assert.equal(context.formatCount(1, '%count% ungelesener Artikel', '%count% ungelesene Artikel'), '1 ungelesener Artikel');
    assert.equal(context.formatCount(2, '%count% ungelesener Artikel', '%count% ungelesene Artikel'), '2 ungelesene Artikel');
    assert.equal(context.formatCount(0, '%count% article restant', '%count% articles restants'), '0 articles restants');
});

test('Zen requests native fullscreen only on mobile and safely ignores unsupported browsers', async () => {
    const context = { document: { fullscreenEnabled: true, fullscreenElement: null, querySelector: () => null } };
    vm.runInNewContext(fs.readFileSync('assets/controllers/zen.js', 'utf8') + '\nthis.requestFullscreen = requestZenFullscreen;', context);

    let calls = 0;
    const zen = { requestFullscreen: () => { calls += 1; return Promise.resolve(); } };
    await context.requestFullscreen(zen, true);
    assert.equal(calls, 1);

    await context.requestFullscreen(zen, false);
    assert.equal(calls, 1);
    await context.requestFullscreen({}, true);
    assert.equal(calls, 1);
});

test('the completed Zen state offers a visible way to close the reader', () => {
    const template = fs.readFileSync('templates/article/index.html.twig', 'utf8');
    assert.match(template, /data-zen-complete[\s\S]*data-zen-complete-close/);
});
