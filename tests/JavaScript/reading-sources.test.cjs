const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('source visibility and links follow the selected reading state', () => {
    const source = fs.readFileSync('assets/controllers/article-list.js', 'utf8');
    const start = source.indexOf('    const updateSources = () => {');
    const end = source.indexOf('\n    updateSources();', start);
    assert.ok(start >= 0 && end > start);
    const rows = [[2, 0], [0, 3], [0, 0]].map(([unread, read]) => {
        const link = { href: 'https://example.test/articles?subscription=1&read=0' };
        return { dataset: { unread: String(unread), read: String(read) }, hidden: false, link, querySelector: () => link };
    });
    const filter = { value: '0' };
    const context = { document: { querySelectorAll: () => rows }, readFilter: filter, URL };
    vm.runInNewContext(source.slice(start, end) + '\nthis.refresh = updateSources;', context);
    context.refresh();
    assert.deepEqual(rows.map(row => row.hidden), [false, true, true]);
    filter.value = '1';
    context.refresh();
    assert.deepEqual(rows.map(row => row.hidden), [true, false, true]);
    assert.equal(new URL(rows[1].link.href).searchParams.get('read'), '1');
    filter.value = '';
    context.refresh();
    assert.deepEqual(rows.map(row => row.hidden), [false, false, true]);
});
