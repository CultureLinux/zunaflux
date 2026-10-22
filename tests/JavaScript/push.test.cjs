const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

test('notification availability is reported in the selected language', () => {
    const status = { textContent: '' };
    const root = {
        dataset: { messages: JSON.stringify({ unavailable: 'Benachrichtigungen sind nicht verfügbar.' }) },
        querySelector: selector => selector === '[role="status"]' ? status : {},
    };
    vm.runInNewContext(fs.readFileSync('public/push.js', 'utf8'), {
        document: { querySelector: () => root }, navigator: {}, window: {},
    });
    assert.equal(status.textContent, 'Benachrichtigungen sind nicht verfügbar.');
});

test('an active subscription registered to this account enables the test and disables activation', async () => {
    const elements = Object.fromEntries(['enable', 'disable', 'test', 'status'].map(name => [name, { disabled: true, dataset: {}, addEventListener() {}, textContent: '' }]));
    const root = { dataset: { publicKey: 'AQ', csrf: 'token', url: '/api/push-subscription', statusUrl: '/api/push-subscription/status' }, querySelector(selector) { return elements[selector.includes('enable') ? 'enable' : selector.includes('disable') ? 'disable' : selector.includes('test') ? 'test' : 'status']; } };
    const subscription = { endpoint: 'https://updates.push.services.mozilla.com/wpush/v2/test' };
    const registration = { pushManager: { getSubscription: async () => subscription } };
    const context = { document: { querySelector: () => root }, navigator: { serviceWorker: { register: async () => registration, ready: Promise.resolve(registration) } }, window: { PushManager: function () {}, isSecureContext: true }, fetch: async () => ({ ok: true, json: async () => ({ subscribed: true }) }), atob, Uint8Array, setTimeout, clearTimeout };
    vm.runInNewContext(fs.readFileSync('public/push.js', 'utf8'), context);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(elements.enable.disabled, true);
    assert.equal(elements.disable.disabled, false);
    assert.equal(elements.test.disabled, false);
    assert.match(elements.status.textContent, /activées/);
});

test('activation creates and registers a subscription before enabling the test', async () => {
    const listeners = {};
    const elements = Object.fromEntries(['enable', 'disable', 'test', 'status'].map(name => [name, { disabled: true, dataset: {}, addEventListener(event, handler) { listeners[name] = handler; }, textContent: '' }]));
    const root = { dataset: { publicKey: 'AQ', csrf: 'token', url: '/api/push-subscription', statusUrl: '/api/push-subscription/status' }, querySelector(selector) { return elements[selector.includes('enable') ? 'enable' : selector.includes('disable') ? 'disable' : selector.includes('test') ? 'test' : 'status']; } };
    const subscription = { endpoint: 'https://updates.push.services.mozilla.com/wpush/v2/test', toJSON: () => ({ endpoint: 'https://updates.push.services.mozilla.com/wpush/v2/test', keys: {} }) };
    let subscribeCalled = false;
    const registration = { pushManager: { getSubscription: async () => null, subscribe: async () => { subscribeCalled = true; return subscription; } } };
    const calls = [];
    const context = { document: { querySelector: () => root }, navigator: { serviceWorker: { register: async () => registration, ready: Promise.resolve(registration) } }, window: { PushManager: function () {}, isSecureContext: true }, fetch: async (url) => { calls.push(url); return { ok: true, json: async () => ({ ok: true }) }; }, atob, Uint8Array, setTimeout, clearTimeout };
    vm.runInNewContext(fs.readFileSync('public/push.js', 'utf8'), context);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(elements.enable.disabled, false);
    assert.equal(elements.test.disabled, true);
    const activation = listeners.enable();
    assert.equal(subscribeCalled, true, 'subscription must start directly during the click');
    await activation;
    assert.deepEqual(calls, ['/api/push-subscription']);
    assert.equal(elements.enable.disabled, true);
    assert.equal(elements.test.disabled, false);
    assert.match(elements.status.textContent, /activées/);
});

test('a stalled browser subscription reports the timeout and allows retry', async () => {
    const listeners = {};
    const elements = Object.fromEntries(['enable', 'disable', 'test', 'status'].map(name => [name, { disabled: true, dataset: {}, addEventListener(event, handler) { listeners[name] = handler; }, textContent: '' }]));
    const root = { dataset: { publicKey: 'AQ', csrf: 'token', url: '/api/push-subscription', statusUrl: '/api/push-subscription/status' }, querySelector(selector) { return elements[selector.includes('enable') ? 'enable' : selector.includes('disable') ? 'disable' : selector.includes('test') ? 'test' : 'status']; } };
    const registration = { pushManager: { getSubscription: async () => null, subscribe: () => new Promise(() => {}) } };
    let timer;
    const context = { document: { querySelector: () => root }, navigator: { serviceWorker: { register: async () => registration, ready: Promise.resolve(registration) } }, window: { PushManager: function () {}, isSecureContext: true }, fetch: async () => { throw new Error('unexpected request'); }, atob, Uint8Array, setTimeout: callback => { timer = callback; return 1; }, clearTimeout() {} };
    vm.runInNewContext(fs.readFileSync('public/push.js', 'utf8'), context);
    await new Promise(resolve => setImmediate(resolve));
    const activation = listeners.enable();
    assert.match(elements.status.textContent, /Connexion/);
    timer();
    await activation;
    assert.equal(elements.enable.disabled, false);
    assert.match(elements.status.textContent, /ne répond pas/);
});

test('a browser push service failure shows its error type without exposing its message', async () => {
    const listeners = {};
    const elements = Object.fromEntries(['enable', 'disable', 'test', 'status'].map(name => [name, { disabled: true, dataset: {}, addEventListener(event, handler) { listeners[name] = handler; }, textContent: '' }]));
    const root = { dataset: { publicKey: 'AQ', csrf: 'token', url: '/api/push-subscription', statusUrl: '/api/push-subscription/status' }, querySelector(selector) { return elements[selector.includes('enable') ? 'enable' : selector.includes('disable') ? 'disable' : selector.includes('test') ? 'test' : 'status']; } };
    const registration = { pushManager: { getSubscription: async () => null, subscribe: () => Promise.reject(Object.assign(new Error('private details'), { name: 'AbortError' })) } };
    const context = { document: { querySelector: () => root }, navigator: { serviceWorker: { register: async () => registration, ready: Promise.resolve(registration) } }, window: { PushManager: function () {}, isSecureContext: true }, atob, Uint8Array, setTimeout, clearTimeout };
    vm.runInNewContext(fs.readFileSync('public/push.js', 'utf8'), context);
    await new Promise(resolve => setImmediate(resolve));
    await listeners.enable();
    assert.match(elements.status.textContent, /AbortError/);
    assert.doesNotMatch(elements.status.textContent, /private details/);
    assert.equal(elements.enable.disabled, false);
});

test('an unregistered browser subscription warns the user and is renewed on activation', async () => {
    const listeners = {};
    const elements = Object.fromEntries(['enable', 'disable', 'test', 'status'].map(name => [name, { disabled: true, dataset: {}, addEventListener(event, handler) { listeners[name] = handler; }, textContent: '' }]));
    const root = { dataset: { publicKey: 'AQ', csrf: 'token', url: '/api/push-subscription', statusUrl: '/api/push-subscription/status' }, querySelector(selector) { return elements[selector.includes('enable') ? 'enable' : selector.includes('disable') ? 'disable' : selector.includes('test') ? 'test' : 'status']; } };
    let unsubscribed = false;
    const old = { endpoint: 'https://fcm.googleapis.com/fcm/send/old', unsubscribe: async () => { unsubscribed = true; return true; } };
    const replacement = { toJSON: () => ({ endpoint: 'https://fcm.googleapis.com/fcm/send/new', keys: {} }) };
    const registration = { pushManager: { getSubscription: async () => old, subscribe: async () => { assert.equal(unsubscribed, true); return replacement; } } };
    const context = { document: { querySelector: () => root }, navigator: { serviceWorker: { register: async () => registration, ready: Promise.resolve(registration) } }, window: { PushManager: function () {}, isSecureContext: true }, fetch: async url => ({ ok: true, json: async () => url.endsWith('/status') ? { subscribed: false, missing: true } : { ok: true } }), atob, Uint8Array, setTimeout, clearTimeout };
    vm.runInNewContext(fs.readFileSync('public/push.js', 'utf8'), context);
    await new Promise(resolve => setImmediate(resolve));
    assert.match(elements.status.textContent, /expir/);
    await listeners.enable();
    assert.equal(unsubscribed, true);
    assert.match(elements.status.textContent, /activées/);
});
