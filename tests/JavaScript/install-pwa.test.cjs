const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

function setup({ dismissedAt = null } = {}) {
    const callbacks = {};
    const action = { hidden: false, textContent: '', addEventListener: (_, fn) => { callbacks.action = fn; } };
    const dismiss = { addEventListener: (_, fn) => { callbacks.dismiss = fn; } };
    const help = { textContent: '' };
    const prompt = { hidden: true, dataset: { installLabel: 'Install', defaultHelp: 'Find your reader among your apps.', helpAndroid: 'Add Zunaflux from your browser.', helpIos: 'Add Zunaflux from Safari.', helpGeneric: 'Open your browser menu to install.', helpAction: 'How to install' }, querySelector: selector => selector.includes('action') ? action : selector.includes('dismiss') ? dismiss : help };
    const events = {};
    const localStorage = new Map(dismissedAt === null ? [] : [['zunaflux-install-dismissed-at', dismissedAt]]);
    const context = { document: { querySelector: () => prompt }, navigator: { userAgent: 'Android Firefox' }, window: { matchMedia: () => ({ matches: false }), addEventListener: (name, fn) => { events[name] = fn; } }, sessionStorage: { getItem: () => null, setItem() {} }, localStorage: { getItem: key => localStorage.get(key) || null, setItem: (key, value) => localStorage.set(key, value) } };
    vm.runInNewContext(fs.readFileSync('public/install-pwa.js', 'utf8'), context);
    return { action, prompt, help, events, callbacks };
}

test('a recent dismissal suppresses the install invitation for 30 days', () => {
    const state = setup({ dismissedAt: String(Date.now()) });

    assert.equal(state.prompt.hidden, true);
});

test('Android without an install prompt offers a visible help action', async () => {
    const state = setup();
    assert.equal(state.prompt.hidden, false);
    assert.equal(state.action.hidden, false);
    await state.callbacks.action();
    assert.equal(state.help.textContent, 'Open your browser menu to install.');
});

test('a browser install prompt can be launched from the action', async () => {
    const state = setup();
    let launched = false;
    state.events.beforeinstallprompt({ preventDefault() {}, prompt: async () => { launched = true; } });
    await state.callbacks.action();
    assert.equal(launched, true);
    assert.equal(state.prompt.hidden, true);
});

test('the installable app declares PNG icons for Android launchers and the Firefox shortcut', () => {
    const base = fs.readFileSync('templates/base.html.twig', 'utf8');
    const manifest = JSON.parse(fs.readFileSync('public/manifest.webmanifest', 'utf8'));

    assert.match(base, /rel="icon"[^>]*icone-pwa-192\.png/);
    assert.ok(manifest.icons.some((icon) => icon.src === '/images/icone-pwa-192.png' && icon.sizes === '192x192' && icon.type === 'image/png'));
    assert.ok(manifest.icons.some((icon) => icon.src === '/images/icone-pwa-512.png' && icon.sizes === '512x512' && icon.type === 'image/png'));
});
