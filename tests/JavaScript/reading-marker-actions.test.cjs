const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('removing a favorite updates the server and removes it from the favorites view', async () => {
    const source = fs.readFileSync('assets/controllers/article-list.js', 'utf8');
    const start = source.indexOf('    const removeReadingMarker = ');
    const end = source.indexOf('\n    };', start) + '\n    };'.length;
    assert.notEqual(start, -1);
    const item = { id: '42', isFavorite: true };
    let removed = false;
    const context = {
        endpoint: '/api/articles', actions: { dataset: { csrfToken: 'token' } },
        fetch: async (url, options) => { assert.equal(url, '/api/articles/bulk'); assert.deepEqual(JSON.parse(options.body), { ids: ['42'], isFavorite: false }); return { ok: true, json: async () => ({ skipped: 0 }) }; },
        bulkError: {}, readFilter: { value: 'favorite' }, loadedItems: [item], selectedIds: new Set(['42']), selectedArticle: null,
        document: { dispatchEvent() {} }, Event: class {}, updateSelection() {},
    };
    vm.runInNewContext(source.slice(start, end) + '\nthis.run = removeReadingMarker;', context);
    await context.run(item, { remove() { removed = true; } }, {}, 'favorite');
    assert.equal(item.isFavorite, false);
    assert.equal(removed, true);
    assert.equal(context.selectedIds.size, 0);
});

test('a failed removal keeps a saved article in the list and reports the error', async () => {
    const source = fs.readFileSync('assets/controllers/article-list.js', 'utf8');
    const start = source.indexOf('    const removeReadingMarker = ');
    const end = source.indexOf('\n    };', start) + '\n    };'.length;
    const item = { id: '42', isSaved: true };
    let removed = false;
    const button = {};
    const context = {
        endpoint: '/api/articles', actions: { dataset: { csrfToken: 'token' } },
        fetch: async (url, options) => { assert.deepEqual(JSON.parse(options.body), { ids: ['42'], isSaved: false }); return { ok: false }; },
        bulkError: {}, readFilter: { value: 'saved' }, loadedItems: [item], selectedIds: new Set(), selectedArticle: null,
    };
    vm.runInNewContext(source.slice(start, end) + '\nthis.run = removeReadingMarker;', context);
    await context.run(item, { remove() { removed = true; } }, button, 'saved');
    assert.equal(item.isSaved, true);
    assert.equal(removed, false);
    assert.equal(context.bulkError.hidden, false);
    assert.equal(button.disabled, false);
});
