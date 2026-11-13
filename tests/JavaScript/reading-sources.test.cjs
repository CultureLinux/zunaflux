const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('both mark-read controls follow the selected source or category', () => {
    const source = fs.readFileSync('assets/controllers/article-list.js', 'utf8');
    const start = source.indexOf('const updateSourceMarkReadControls =');
    const end = source.indexOf('\nconst container =', start);
    assert.ok(start >= 0 && end > start);
    const forms = ['first', 'second'].flatMap(() => [{ dataset: { subscriptionId: 'a' } }, { dataset: { subscriptionId: 'b' } }, { dataset: { categoryId: 'category' } }]);
    const context = {};
    vm.runInNewContext(source.slice(start, end) + '\nthis.update = updateSourceMarkReadControls;', context);
    context.update(forms, 'b', '');
    assert.deepEqual(forms.map(form => form.hidden), [true, false, true, true, false, true]);
    context.update(forms, '', 'category');
    assert.deepEqual(forms.map(form => form.hidden), [true, true, false, true, true, false]);
    context.update(forms, '', '');
    assert.ok(forms.every(form => form.hidden));
});

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
    const category = { href: 'https://example.test/articles?category=1&read=0' };
    const context = { document: { querySelectorAll: selector => selector === '[data-reading-source]' ? rows : selector === '[data-reading-category]' ? [category] : [] }, readFilter: filter, categoryFilter: '', URL };
    vm.runInNewContext(source.slice(0, source.indexOf('const container =')) + source.slice(start, end) + '\nthis.refresh = updateSources;', context);
    context.refresh();
    assert.deepEqual(rows.map(row => row.hidden), [false, true, true]);
    filter.value = '1';
    context.refresh();
    assert.deepEqual(rows.map(row => row.hidden), [true, false, true]);
    assert.equal(new URL(rows[1].link.href).searchParams.get('read'), '1');
    assert.equal(new URL(category.href).searchParams.get('read'), '1');
    filter.value = '';
    context.refresh();
    assert.deepEqual(rows.map(row => row.hidden), [false, false, true]);
});

test('category groups follow the selected category and hide empty unread groups', () => {
    const source = fs.readFileSync('assets/controllers/article-list.js', 'utf8');
    const start = source.indexOf('    const updateSources = () => {');
    const end = source.indexOf('\n    updateSources();', start);
    const rows = ['a', 'b'].map((id, index) => ({ dataset: { categoryId: id, unread: String(index ? 0 : 2), read: '1' }, hidden: false, querySelector: () => ({ href: 'https://example.test/articles' }) }));
    const groups = rows.map(row => ({ dataset: { readingCategoryGroup: row.dataset.categoryId }, hidden: false, querySelectorAll: () => [row] }));
    const filter = { value: '0' };
    const context = { document: { querySelectorAll: selector => selector === '[data-reading-source]' ? rows : selector === '[data-reading-category-group]' ? groups : [] }, readFilter: filter, categoryFilter: '', URL };
    vm.runInNewContext(source.slice(0, source.indexOf('const container =')) + source.slice(start, end) + '\nthis.refresh = updateSources;', context);
    context.refresh();
    assert.deepEqual(groups.map(group => group.hidden), [false, true]);
    filter.value = '';
    context.refresh();
    assert.deepEqual(groups.map(group => group.hidden), [false, false]);
    context.categoryFilter = 'b';
    context.refresh();
    assert.deepEqual(groups.map(group => group.hidden), [true, false]);
    context.categoryFilter = '';
    context.refresh();
    assert.deepEqual(groups.map(group => group.hidden), [false, false]);
});
