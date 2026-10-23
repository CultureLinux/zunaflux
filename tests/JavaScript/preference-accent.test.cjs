const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('changing the accent updates the selected color without submitting the form', () => {
    let change;
    const select = { value: 'blue', dataset: { accentValue: 'cyan' }, addEventListener: (type, handler) => { if (type === 'change') change = handler; } };
    vm.runInNewContext(fs.readFileSync('public/preference-accent.js', 'utf8'), { document: { querySelector: () => select } });
    change();
    assert.equal(select.dataset.accentValue, 'blue');
});
