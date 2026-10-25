const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('refreshing a subscription opens its localized status dialog', async () => {
    let submitHandler;
    let opened = false;
    const log = { textContent: '', rows: [], replaceChildren() { this.rows = []; }, append(...rows) { this.rows.push(...rows); } };
    const dialog = {
        dataset: {
            queued: 'Queued…',
            pending: 'Waiting',
            running: 'Fetching',
            collected: 'Collected',
            empty: 'No new articles',
            error: 'Error',
            newArticles: '%count% new article(s)',
            networkError: 'Unable to contact the server.',
        },
        showModal() { opened = true; },
        querySelector(selector) { return selector === '[data-subscription-refresh-log]' ? log : { addEventListener() {} }; },
    };
    const form = {
        action: '/subscriptions/subscription-1/refresh',
        dataset: { subscriptionRefreshDialog: 'refresh-subscription-1', statusUrl: '/subscriptions/subscription-1/refresh/__run_id__' },
        querySelector() { return { value: 'csrf-token' }; },
        addEventListener(event, handler) { if (event === 'submit') submitHandler = handler; },
    };
    const calls = [];
    const context = {
        document: {
            querySelectorAll: () => [form],
            getElementById: () => dialog,
            createElement: () => ({ className: '', textContent: '', children: [], append(...children) { this.children.push(...children); } }),
        },
        FormData: class FormData {},
        fetch: async (url, options) => {
            calls.push({ url, options });
            if (calls.length === 1) return { ok: true, json: async () => ({ runId: 'run-1' }) };
            return { ok: true, json: async () => ({ entries: [{ title: 'Feed', status: 'collected', new_articles: 2, detail: null }], complete: true }) };
        },
        window: { setTimeout() {} },
        HTMLDialogElement: function HTMLDialogElement() {},
    };
    Object.setPrototypeOf(dialog, context.HTMLDialogElement.prototype);

    vm.runInNewContext(fs.readFileSync('assets/controllers/subscription-refresh.js', 'utf8'), context);
    await submitHandler({ preventDefault() {}, submitter: {} });

    assert.deepEqual(calls.map(call => call.url), ['/subscriptions/subscription-1/refresh', '/subscriptions/subscription-1/refresh/run-1']);
    assert.equal(calls[0].options.headers['X-CSRF-Token'], 'csrf-token');
    assert.equal(opened, true);
    assert.equal(log.rows[0].children[1].textContent, 'Feed — Collected : 2 new article(s)');
});
