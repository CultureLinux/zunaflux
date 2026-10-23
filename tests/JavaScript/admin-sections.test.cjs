const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('selecting an administration section shows it and hides the others', () => {
    const names = ['configuration', 'incidents', 'accounts', 'stats'];
    const panels = Object.fromEntries(names.map(name => [name, { hidden: false }]));
    const buttons = names.map(name => ({
        dataset: { adminSection: name },
        setAttribute(key, value) { this[key] = value; },
        addEventListener(event, handler) { this[event] = handler; },
    }));
    const document = {
        querySelectorAll: selector => selector === '[data-admin-section]' ? buttons : [],
        getElementById: id => panels[id.replace('admin-', '')],
    };
    vm.runInNewContext(fs.readFileSync('assets/controllers/admin-sections.js', 'utf8'), { document, location: { hash: '' }, history: { replaceState() {} } });
    buttons[1].click();
    assert.equal(panels.incidents.hidden, false);
    assert.equal(panels.configuration.hidden, true);
    assert.equal(panels.accounts.hidden, true);
    assert.equal(panels.stats.hidden, true);
    assert.equal(buttons[1]['aria-pressed'], 'true');
});
