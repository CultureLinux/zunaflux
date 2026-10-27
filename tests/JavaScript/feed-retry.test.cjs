const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('a successful retry removes its incident and announces the recovered feed', async () => {
    let submitHandler;
    let removed = false;
    let closed = false;
    const events = [];
    const log = { textContent: '' };
    const button = { disabled: false };
    const dialog = {
        dataset: { loading: 'Testing…', error: 'Test failed.', success: 'The feed is working again.' },
        showModal() {},
        close() { closed = true; },
        querySelector(selector) { return selector === '[data-feed-retry-log]' ? log : button; },
        querySelectorAll() { return [{ addEventListener() {} }]; },
    };
    const row = { remove() { removed = true; } };
    const form = {
        action: '/admin/incidents/retry',
        nextElementSibling: dialog,
        querySelector() { return button; },
        closest(selector) { return selector === 'li' ? row : null; },
        addEventListener(event, handler) { if (event === 'submit') submitHandler = handler; },
    };
    const context = {
        document: { querySelectorAll: () => [form] },
        FormData: class FormData {},
        fetch: async () => ({ ok: true, json: async () => ({ success: true, logs: ['Fetch succeeded.'] }) }),
        window: { dispatchEvent(event) { events.push(event); } },
        CustomEvent: class CustomEvent { constructor(type, init) { this.type = type; this.detail = init.detail; } },
        HTMLDialogElement: function HTMLDialogElement() {},
    };
    Object.setPrototypeOf(dialog, context.HTMLDialogElement.prototype);

    vm.runInNewContext(fs.readFileSync('assets/controllers/feed-retry.js', 'utf8'), context);
    await submitHandler({ preventDefault() {} });

    assert.equal(log.textContent, 'Fetch succeeded.');
    assert.equal(closed, true);
    assert.equal(removed, true);
    assert.equal(events.length, 1);
    assert.ok(events[0] instanceof context.CustomEvent);
    assert.equal(events[0].type, 'zunaflux:toast');
    assert.equal(events[0].detail.type, 'success');
    assert.equal(events[0].detail.message, 'The feed is working again.');
});
