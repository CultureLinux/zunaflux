const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('synchronization labels are supplied by the translated template', () => {
    const template = fs.readFileSync('templates/admin/index.html.twig', 'utf8');

    assert.match(template, /data-feed-sync-queued="\{\{ 'Mise en file…'\|trans \}\}"/);
    assert.match(template, /data-feed-sync-status-pending="\{\{ 'En attente du worker'\|trans \}\}"/);
    assert.match(template, /data-feed-sync-status-error="\{\{ 'Erreur'\|trans \}\}"/);
});

test('a failed status poll identifies the HTTP error without exposing its body', async () => {
    let submit;
    const log = {
        textContent: '',
        replaceChildren() {},
        append() {},
    };
    const close = { addEventListener() {} };
    const dialog = {
        showModal() {},
        close() {},
        querySelector(selector) {
            return selector === '[data-feed-sync-log]' ? log : close;
        },
    };
    const form = {
        action: '/admin/feed-sync',
        dataset: {
            statusUrl: '/admin/feed-sync/__run_id__',
            feedSyncQueued: 'Queued…',
            feedSyncStatusPending: 'Pending',
            feedSyncStatusRunning: 'Collecting',
            feedSyncStatusCollected: 'Collected',
            feedSyncStatusEmpty: 'No new articles',
            feedSyncStatusError: 'Error',
            feedSyncNewArticles: '%count% new article(s)',
            feedSyncStartError: 'Start failed (HTTP %status%).',
            feedSyncStatusErrorMessage: 'Status failed (HTTP %status%).',
            feedSyncNetworkError: 'Unable to contact the server.',
        },
        querySelector() { return { value: 'csrf-token' }; },
        addEventListener(event, handler) { if (event === 'submit') submit = handler; },
    };
    const calls = [];
    const context = {
        document: {
            querySelectorAll: () => [form],
            querySelector: () => dialog,
        },
        FormData: class FormData {},
        fetch: async (url, options) => {
            calls.push({ url, options });
            if (calls.length === 1) return { ok: true, json: async () => ({ runId: 'run-1' }) };
            return { ok: false, status: 404, text: async () => 'private server response' };
        },
        window: { setTimeout() {} },
        HTMLDialogElement: function HTMLDialogElement() {},
    };
    Object.setPrototypeOf(dialog, context.HTMLDialogElement.prototype);

    vm.runInNewContext(fs.readFileSync('assets/controllers/feed-sync.js', 'utf8'), context);
    await submit({ preventDefault() {}, submitter: {} });

    assert.deepEqual(calls.map(call => call.url), ['/admin/feed-sync', '/admin/feed-sync/run-1']);
    assert.equal(calls[0].options.headers['X-CSRF-Token'], 'csrf-token');
    assert.equal(log.textContent, 'Status failed (HTTP 404).');
    assert.doesNotMatch(log.textContent, /private server response/);
});
