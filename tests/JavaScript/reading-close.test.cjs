const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('closing the reader refreshes a list filtered by read state after pending updates', async () => {
    const source = fs.readFileSync('assets/controllers/article-list.js', 'utf8');
    const listeners = {};
    let refreshes = 0;
    const dialog = { addEventListener: (event, callback) => { listeners[event] = callback; } };
    let resolveUpdate;
    const pendingStateUpdates = new Set([new Promise(resolve => { resolveUpdate = resolve; })]);
    const context = { dialog, pendingStateUpdates, readFilter: { value: '0' }, loadPage: () => { refreshes++; } };
    const start = source.indexOf('    dialog.addEventListener(\'close\'');
    if (start !== -1) {
        const end = source.indexOf('\n    });', start) + '\n    });'.length;
        vm.runInNewContext(source.slice(start, end), context);
    }
    assert.equal(typeof listeners.close, 'function');
    listeners.close();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(refreshes, 0);
    resolveUpdate();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(refreshes, 1);
    context.readFilter.value = '';
    listeners.close();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(refreshes, 1);
});
