const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

async function renderCount(response) {
    const badge = { textContent: '…' };
    const link = { dataset: { openOne: '%count% article non lu', openMany: '%count% articles non lus' }, setAttribute(name, value) { this[name] = value; } };
    const context = {
        document: { querySelector: selector => selector === '[data-zen-nav]' ? link : badge },
        fetch: async () => response,
    };
    vm.runInNewContext(fs.readFileSync('assets/controllers/zen-nav-count.js', 'utf8'), context);
    await new Promise(resolve => setImmediate(resolve));
    return { badge, link };
}

test('the mobile Zen link displays the unread count and an accessible label', async () => {
    const { badge, link } = await renderCount({ ok: true, json: async () => ({ all: { unread_count: 2 } }) });
    assert.equal(badge.textContent, '2');
    assert.equal(link['aria-label'], '2 articles non lus');
});

test('the mobile Zen link indicates when the unread count is unavailable', async () => {
    const { badge } = await renderCount({ ok: false });
    assert.equal(badge.textContent, '–');
});
