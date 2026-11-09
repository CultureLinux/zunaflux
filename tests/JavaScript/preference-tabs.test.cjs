const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const createButton = name => {
    const attributes = {};
    const listeners = {};
    return {
        dataset: { preferenceTab: name },
        addEventListener: (type, listener) => { listeners[type] = listener; },
        getAttribute: name => attributes[name],
        setAttribute: (name, value) => { attributes[name] = value; },
        focus: () => { attributes.focused = 'true'; },
        listeners,
    };
};

test('selecting a preference tab shows only its matching panel', () => {
    const general = createButton('general');
    const mastodon = createButton('mastodon');
    const panels = {
        'preference-general': { hidden: false },
        'preference-mastodon': { hidden: false },
    };

    vm.runInNewContext(fs.readFileSync('public/preference-tabs.js', 'utf8'), {
        document: {
            querySelectorAll: () => [general, mastodon],
            getElementById: id => panels[id],
        },
    });

    mastodon.listeners.click();

    assert.equal(panels['preference-general'].hidden, true);
    assert.equal(panels['preference-mastodon'].hidden, false);
    assert.equal(general.getAttribute('aria-selected'), 'false');
    assert.equal(mastodon.getAttribute('aria-selected'), 'true');
});
