const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('marking an unread article updates the server and removes it from the unread list', async () => {
    const source = fs.readFileSync('assets/controllers/article-list.js', 'utf8');
    const start = source.indexOf('    const markArticleRead = ');
    const end = source.indexOf('\n    };', start) + '\n    };'.length;
    assert.notEqual(start, -1, 'the list must provide a mark-as-read action');
    const item = { id: '42', isRead: false };
    const selectedIds = new Set(['42']);
    let removed = false;
    const row = { remove: () => { removed = true; } };
    const button = {};
    const context = {
        endpoint: '/api/articles', actions: { dataset: { csrfToken: 'token' } },
        fetch: async (url, options) => {
            assert.equal(url, '/api/articles/bulk');
            assert.deepEqual(JSON.parse(options.body), { ids: ['42'], isRead: true });
            return { ok: true, json: async () => ({ skipped: 0 }) };
        },
        readFilter: { value: '0' }, loadedItems: [item], selectedIds,
        updateUnreadCount() {}, updateSelection() {}, bulkError: {},
    };
    vm.runInNewContext(source.slice(start, end) + '\nthis.run = markArticleRead;', context);
    await context.run(item, row, button);
    assert.equal(item.isRead, true);
    assert.equal(removed, true);
    assert.equal(selectedIds.size, 0);
    assert.equal(context.loadedItems.length, 0);
});
