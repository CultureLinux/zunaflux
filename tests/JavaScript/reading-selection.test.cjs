const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('bulk controls stay visible and are enabled only after an article is selected', () => {
    const source = fs.readFileSync('assets/controllers/article-list.js', 'utf8');
    const start = source.indexOf('    const updateSelection = () => {');
    const end = source.indexOf('\n    updateSelection();', start);
    assert.ok(start >= 0 && end > start);
    const selectedIds = new Set();
    const buttons = [{ disabled: false }, { disabled: false }];
    const actions = { querySelectorAll: () => buttons };
    const selectAllButton = { setAttribute() {} };
    const selectedCount = {};
    const context = { container: { querySelectorAll: () => [] }, selectedIds, actions, selectAllButton, selectedCount };
    vm.runInNewContext(source.slice(start, end) + '\nthis.update = updateSelection;', context);
    context.update();
    assert.equal(buttons[0].disabled, true);
    assert.equal(selectAllButton.hidden, true);
    selectedIds.add('1');
    context.update();
    assert.equal(buttons[0].disabled, false);
    assert.equal(selectAllButton.hidden, false);
    assert.match(selectedCount.textContent, /1/);
    selectedIds.clear();
    context.update();
    assert.equal(buttons[0].disabled, true);
});
