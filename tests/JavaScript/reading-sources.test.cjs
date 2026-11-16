const { test } = require('node:test');
const assert = require('node:assert/strict');
const { updateSourceMarkReadControls, updateReadingSources } = require('../../assets/reading/sources.js');

test('both mark-read controls follow the selected source or category', () => {
    const forms = ['first', 'second'].flatMap(() => [{ dataset: { subscriptionId: 'a' } }, { dataset: { subscriptionId: 'b' } }, { dataset: { categoryId: 'category' } }]);
    updateSourceMarkReadControls(forms, 'b', '');
    assert.deepEqual(forms.map(form => form.hidden), [true, false, true, true, false, true]);
    updateSourceMarkReadControls(forms, '', 'category');
    assert.deepEqual(forms.map(form => form.hidden), [true, true, false, true, true, false]);
    updateSourceMarkReadControls(forms, '', '');
    assert.ok(forms.every(form => form.hidden));
});

test('source visibility and links follow the selected reading state', () => {
    const rows = [[2, 0], [0, 3], [0, 0]].map(([unread, read]) => {
        const link = { href: 'https://example.test/articles?subscription=1&read=0' };
        return { dataset: { unread: String(unread), read: String(read) }, hidden: false, link, querySelector: () => link };
    });
    const filter = { value: '0' };
    const category = { href: 'https://example.test/articles?category=1&read=0' };
    const context = { document: { querySelectorAll: selector => selector === '[data-reading-source]' ? rows : selector === '[data-reading-category]' ? [category] : [] }, readFilter: filter, categoryFilter: '', URL };
    updateReadingSources(context.document, filter.value, context.categoryFilter);
    assert.deepEqual(rows.map(row => row.hidden), [false, true, true]);
    filter.value = '1';
    updateReadingSources(context.document, filter.value, context.categoryFilter);
    assert.deepEqual(rows.map(row => row.hidden), [true, false, true]);
    assert.equal(new URL(rows[1].link.href).searchParams.get('read'), '1');
    assert.equal(new URL(category.href).searchParams.get('read'), '1');
    filter.value = '';
    updateReadingSources(context.document, filter.value, context.categoryFilter);
    assert.deepEqual(rows.map(row => row.hidden), [false, false, true]);
});

test('category groups follow the selected category and hide empty unread groups', () => {
    const rows = ['a', 'b'].map((id, index) => ({ dataset: { categoryId: id, unread: String(index ? 0 : 2), read: '1' }, hidden: false, querySelector: () => ({ href: 'https://example.test/articles' }) }));
    const groups = rows.map(row => ({ dataset: { readingCategoryGroup: row.dataset.categoryId }, hidden: false, querySelectorAll: () => [row] }));
    const filter = { value: '0' };
    const context = { document: { querySelectorAll: selector => selector === '[data-reading-source]' ? rows : selector === '[data-reading-category-group]' ? groups : [] }, readFilter: filter, categoryFilter: '', URL };
    updateReadingSources(context.document, filter.value, context.categoryFilter);
    assert.deepEqual(groups.map(group => group.hidden), [false, true]);
    filter.value = '';
    updateReadingSources(context.document, filter.value, context.categoryFilter);
    assert.deepEqual(groups.map(group => group.hidden), [false, false]);
    context.categoryFilter = 'b';
    updateReadingSources(context.document, filter.value, context.categoryFilter);
    assert.deepEqual(groups.map(group => group.hidden), [true, false]);
    context.categoryFilter = '';
    updateReadingSources(context.document, filter.value, context.categoryFilter);
    assert.deepEqual(groups.map(group => group.hidden), [false, false]);
});
