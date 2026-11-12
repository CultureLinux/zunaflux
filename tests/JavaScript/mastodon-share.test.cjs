const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const loadComposer = () => {
    const context = { window: { addEventListener: () => {} }, document: { querySelector: () => null }, URL };
    vm.runInNewContext(fs.readFileSync('assets/controllers/mastodon-share.js', 'utf8') + '\nthis.defaultMastodonStatus = defaultMastodonStatus; this.hasDirectRecipient = hasDirectRecipient; this.mastodonPublicationError = mastodonPublicationError;', context);
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

test('a redirected Mastodon publication request tells the user to reconnect', () => {
    const composer = loadComposer();

    assert.equal(
        composer.mastodonPublicationError({ redirected: true, url: 'https://zunaflux.example/login', status: 200 }, {}),
        'Votre session a expiré. Reconnectez-vous puis réessayez.',
    );
});

test('a successful Mastodon publication hides the publish button', async () => {
    const listeners = {};
    const createElement = (attributes = {}) => ({
        ...attributes,
        hidden: false,
        disabled: false,
        value: '',
        addEventListener: (event, callback) => { listeners[event] = callback; },
        focus: () => {},
        removeAttribute: () => {},
    });
    const form = createElement();
    const status = createElement({ value: 'Une lecture' });
    const visibility = createElement({ value: 'public' });
    const count = createElement();
    const directHelp = createElement();
    const error = createElement();
    const success = createElement();
    const link = createElement();
    const submit = createElement();
    const dialog = {
        querySelector: (selector) => ({
            '[data-mastodon-share-form]': form,
            '[data-mastodon-share-status]': status,
            '[data-mastodon-share-visibility]': visibility,
            '[data-mastodon-share-count]': count,
            '[data-mastodon-share-direct-help]': directHelp,
            '[data-mastodon-share-error]': error,
            '[data-mastodon-share-success]': success,
            '[data-mastodon-share-link]': link,
            '[data-mastodon-share-submit]': submit,
        })[selector],
        querySelectorAll: () => [],
        showModal: () => {},
    };
    const windowListeners = {};
    const context = {
        window: { addEventListener: (event, callback) => { windowListeners[event] = callback; } },
        document: { querySelector: () => dialog },
        URL,
        fetch: async () => ({ ok: true, json: async () => ({ url: 'https://mastodon.example/@reader/123' }) }),
    };
    vm.runInNewContext(fs.readFileSync('assets/controllers/mastodon-share.js', 'utf8'), context);

    windowListeners['zunaflux:mastodon-share']({ detail: { id: 42, title: 'Une lecture', url: 'https://example.test', mastodonCsrfToken: 'token' } });
    await listeners.submit({ preventDefault: () => {} });

    assert.equal(success.hidden, false);
    assert.equal(submit.hidden, true);
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
