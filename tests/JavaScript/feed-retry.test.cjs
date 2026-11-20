const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function setup(responses) {
    let submitHandler;
    const state = { removed: false, closed: false, events: [], urls: [], timers: 0 };
    const log = { textContent: '' };
    const button = { disabled: false };
    const dialog = {
        open: true,
        dataset: {
            loading: 'Testing…', error: 'Test failed.', success: 'The feed is working again.', statusUrl: '/admin/feed-sync/__run_id__',
            labelPending: 'Waiting', labelRunning: 'Running', labelCollected: 'Added', labelEmpty: 'No new article', labelError: 'Error', newArticles: '%count% new',
        },
        showModal() {},
        close() { state.closed = true; },
        querySelector(selector) { return selector === '[data-feed-retry-log]' ? log : button; },
        querySelectorAll() { return [{ addEventListener() {} }]; },
    };
    const row = { remove() { state.removed = true; } };
    const form = {
        action: '/admin/incidents/1/retry',
        nextElementSibling: dialog,
        querySelector() { return button; },
        closest(selector) { return selector === 'li' ? row : null; },
        addEventListener(event, handler) { if (event === 'submit') submitHandler = handler; },
    };
    const queue = [...responses];
    const context = {
        document: { querySelectorAll: () => [form] },
        FormData: class FormData {},
        fetch: async (url) => { state.urls.push(url); const next = queue.shift(); if (next instanceof Error) throw next; return { ok: next.ok ?? true, json: async () => next.body }; },
        window: { dispatchEvent(event) { state.events.push(event); }, setTimeout: (fn) => { state.timers += 1; fn(); } },
        CustomEvent: class CustomEvent { constructor(type, init) { this.type = type; this.detail = init.detail; } },
        HTMLDialogElement: function HTMLDialogElement() {},
        Promise,
    };
    Object.setPrototypeOf(dialog, context.HTMLDialogElement.prototype);
    vm.runInNewContext(fs.readFileSync('assets/controllers/feed-retry.js', 'utf8'), context);

    return { submit: () => submitHandler({ preventDefault() {} }), state, log, dialog, button };
}

test('a retry is queued, followed until it settles, then removes the incident and announces the recovery', async () => {
    const { submit, state, log } = setup([
        { body: { runId: 'run-1' } },
        { body: { complete: false, entries: [{ title: 'Blog', canonical_url: 'https://e.test/f', status: 'running', new_articles: 0, detail: null }] } },
        { body: { complete: true, entries: [{ title: 'Blog', canonical_url: 'https://e.test/f', status: 'collected', new_articles: 3, detail: null }] } },
    ]);

    await submit();

    assert.deepEqual(state.urls, ['/admin/incidents/1/retry', '/admin/feed-sync/run-1', '/admin/feed-sync/run-1']);
    assert.equal(log.textContent, 'Blog — Added : 3 new');
    assert.equal(state.timers, 1, 'one pause between two polls');
    assert.equal(state.closed, true);
    assert.equal(state.removed, true);
    assert.equal(state.events.length, 1);
    assert.equal(state.events[0].type, 'zunaflux:toast');
    assert.equal(state.events[0].detail.message, 'The feed is working again.');
});

test('a failed retry keeps the dialog open with the translated reason and leaves the incident', async () => {
    const { submit, state, log, button } = setup([
        { body: { runId: 'run-2' } },
        { body: { complete: true, entries: [{ title: null, canonical_url: 'https://e.test/f', status: 'error', new_articles: 0, detail: 'The site answered HTTP 503.' }] } },
    ]);

    await submit();

    assert.equal(log.textContent, 'https://e.test/f — Error · The site answered HTTP 503.');
    assert.equal(state.closed, false);
    assert.equal(state.removed, false);
    assert.equal(state.events.length, 0);
    assert.equal(button.disabled, false);
});

test('network and server errors show the generic message and re-enable the button', async () => {
    const offline = setup([new TypeError('Failed to fetch')]);
    await offline.submit();
    assert.equal(offline.log.textContent, 'Test failed.');
    assert.equal(offline.button.disabled, false);

    const refused = setup([{ ok: false, body: {} }]);
    await refused.submit();
    assert.equal(refused.log.textContent, 'Test failed.');

    const brokenPoll = setup([{ body: { runId: 'run-3' } }, { ok: false, body: {} }]);
    await brokenPoll.submit();
    assert.equal(brokenPoll.log.textContent, 'Test failed.');
    assert.equal(brokenPoll.state.removed, false);
});

test('closing the dialog stops the polling', async () => {
    const { submit, state, dialog } = setup([
        { body: { runId: 'run-4' } },
        { body: { complete: false, entries: [{ title: 'Blog', canonical_url: 'u', status: 'running', new_articles: 0, detail: null }] } },
    ]);
    dialog.open = false;

    await submit();

    assert.equal(state.urls.length, 2);
    assert.equal(state.removed, false);
    assert.equal(state.timers, 0);
});
