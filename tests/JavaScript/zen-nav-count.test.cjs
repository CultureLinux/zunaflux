const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { installReadingApi, jsonResponse } = require('../Support/reading-api.cjs');

async function renderCount(response) {
    const badge = { textContent: '…' };
    const link = { dataset: { openOne: '%count% article non lu', openMany: '%count% articles non lus' }, setAttribute(name, value) { this[name] = value; } };
    const context = {
        document: { querySelector: selector => selector === '[data-zen-nav]' ? link : badge },
        fetch: async () => response,
    };
    installReadingApi(context);
    vm.runInContext(fs.readFileSync('assets/controllers/zen-nav-count.js', 'utf8').replace(/^import .*\n/gm, ''), context);
    await new Promise(resolve => setImmediate(resolve));
    return { badge, link };
}

test('the mobile Zen link displays the unread count and an accessible label', async () => {
    const { badge, link } = await renderCount(jsonResponse({ all: { unread_count: 2 } }));
    assert.equal(badge.textContent, '2');
    assert.equal(link['aria-label'], '2 articles non lus');
});

test('the mobile Zen link indicates when the unread count is unavailable', async () => {
    const { badge } = await renderCount(jsonResponse({}, { ok: false, status: 500 }));
    assert.equal(badge.textContent, '–');
});
