const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const loadComposer = () => {
    const context = { window: { addEventListener: () => {} }, document: { querySelector: () => null }, URL };
    vm.runInNewContext(fs.readFileSync('assets/controllers/mastodon-share.js', 'utf8') + '\nthis.defaultMastodonStatus = defaultMastodonStatus; this.hasDirectRecipient = hasDirectRecipient;', context);
    return context;
};

test('the Mastodon composer defaults to the title, original URL and public visibility', () => {
    const composer = loadComposer();

    assert.equal(composer.defaultMastodonStatus({ title: 'Une lecture', url: 'https://example.test/article' }), 'Une lecture\nhttps://example.test/article');
    assert.equal(composer.defaultMastodonStatus({ title: 'Une lecture', url: 'mailto:author@example.test' }), 'Une lecture');
});

test('direct visibility requires a Mastodon recipient mention before submission', () => {
    const composer = loadComposer();

    assert.equal(composer.hasDirectRecipient('Bonjour @alice@mastodon.example'), true);
    assert.equal(composer.hasDirectRecipient('Bonjour'), false);
});

test('the share dialog exposes editable status, visibility choices and inline feedback', () => {
    const template = fs.readFileSync('templates/article/_mastodon_share_dialog.html.twig', 'utf8');

    assert.match(template, /data-mastodon-share-status/);
    assert.match(template, /data-mastodon-share-visibility/);
    assert.match(template, /value="public" selected/);
    assert.match(template, /value="unlisted"/);
    assert.match(template, /value="private"/);
    assert.match(template, /value="direct"/);
    assert.match(template, /data-mastodon-share-error/);
    assert.match(template, /data-mastodon-share-success/);
});
