const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('bulk actions reflect the states of the selected articles', () => {
    const source = fs.readFileSync('assets/controllers/article-list.js', 'utf8');
    const start = source.indexOf('    const updateSelection = () => {');
    const end = source.indexOf('\n    updateSelection();', start);
    const selectedIds = new Set(['1']);
    const loadedItems = [{ id: '1', isRead: true, isFavorite: false, isSaved: true }, { id: '2', isRead: false, isFavorite: true, isSaved: false }];
    const buttons = Object.fromEntries(['read', 'unread', 'favorite', 'unfavorite', 'save', 'unsave'].map(key => [key, {}]));
    const actions = { querySelector: selector => buttons[selector.match(/data-bulk-(\w+)/)[1]] };
    const context = { container: { querySelectorAll: () => [] }, selectedIds, loadedItems, actions, selectAllButton: { setAttribute() {} }, selectedCount: {} };
    vm.runInNewContext(source.slice(start, end) + '\nthis.update = updateSelection;', context);
    context.update();
    assert.equal(buttons.read.hidden, true);
    assert.equal(buttons.unread.hidden, false);
    assert.equal(buttons.favorite.hidden, false);
    assert.equal(buttons.unfavorite.hidden, true);
    assert.equal(buttons.save.hidden, true);
    assert.equal(buttons.unsave.hidden, false);
    selectedIds.add('2');
    context.update();
    assert.equal(buttons.read.hidden, false);
    assert.equal(buttons.unread.hidden, false);
    assert.equal(buttons.favorite.hidden, false);
    assert.equal(buttons.unfavorite.hidden, false);
});
