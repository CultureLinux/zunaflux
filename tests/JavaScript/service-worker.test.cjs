const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const run = ({ windows = [], notificationData }) => {
    const handlers = {};
    const calls = { opened: [], focused: [], navigated: [], closed: false };
    const context = {
        URL,
        self: { location: { origin: 'https://zunaflux.test' }, addEventListener: (type, handler) => { handlers[type] = handler; }, registration: {} },
        clients: {
            matchAll: async () => windows.map((win) => ({
                url: win.url,
                focus: async function () { calls.focused.push(win.url); return this; },
                navigate: async (href) => { calls.navigated.push(href); },
            })),
            openWindow: async (href) => { calls.opened.push(href); },
        },
    };
    vm.runInNewContext(fs.readFileSync('public/sw.js', 'utf8'), context);
    let pending;
    handlers.notificationclick({ notification: { data: notificationData, close: () => { calls.closed = true; } }, waitUntil: (promise) => { pending = promise; } });
    return pending.then(() => calls);
};

test('a click opens a window when none is open', async () => {
    const calls = await run({ notificationData: { url: '/articles' } });
    assert.deepEqual(calls.opened, ['https://zunaflux.test/articles']);
    assert.equal(calls.closed, true);
});

test('a click focuses an existing tab instead of opening a new window', async () => {
    const calls = await run({ windows: [{ url: 'https://zunaflux.test/articles' }], notificationData: { url: '/articles' } });
    assert.deepEqual(calls.opened, []);
    assert.deepEqual(calls.focused, ['https://zunaflux.test/articles']);
    assert.deepEqual(calls.navigated, []);
});

test('a click navigates an existing tab that shows another page', async () => {
    const calls = await run({ windows: [{ url: 'https://zunaflux.test/preferences' }], notificationData: { url: '/articles' } });
    assert.deepEqual(calls.navigated, ['https://zunaflux.test/articles']);
});

test('a foreign origin in the payload is ignored', async () => {
    const calls = await run({ notificationData: { url: 'https://evil.test/phish' } });
    assert.deepEqual(calls.opened, ['https://zunaflux.test/articles']);
});
