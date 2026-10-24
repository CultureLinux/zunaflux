const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

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
        dataset: { statusUrl: '/admin/feed-sync/__run_id__' },
        querySelector() { return null; },
        addEventListener(event, handler) { if (event === 'submit') submit = handler; },
    };
    const calls = [];
    const context = {
        document: {
            querySelectorAll: () => [form],
            querySelector: () => dialog,
        },
        FormData: class FormData {},
        fetch: async url => {
            calls.push(url);
            if (calls.length === 1) return { ok: true, json: async () => ({ runId: 'run-1' }) };
            return { ok: false, status: 404, text: async () => 'private server response' };
        },
        window: { setTimeout() {} },
        HTMLDialogElement: function HTMLDialogElement() {},
    };
    Object.setPrototypeOf(dialog, context.HTMLDialogElement.prototype);

    vm.runInNewContext(fs.readFileSync('assets/controllers/feed-sync.js', 'utf8'), context);
    await submit({ preventDefault() {}, submitter: {} });

    assert.deepEqual(calls, ['/admin/feed-sync', '/admin/feed-sync/run-1']);
    assert.match(log.textContent, /suivi.*HTTP 404/i);
    assert.doesNotMatch(log.textContent, /private server response/);
});
