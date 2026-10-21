const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('zen gestures identify the four directions and ignore short movements', () => {
    const context = { document: { querySelector: () => null } };
    vm.runInNewContext(fs.readFileSync('assets/controllers/zen.js', 'utf8') + '\nthis.classify = classifyZenGesture;', context);
    assert.equal(context.classify(-90, 5), 'next');
    assert.equal(context.classify(90, 5), 'previous');
    assert.equal(context.classify(5, 90), 'favorite');
    assert.equal(context.classify(5, -90), 'save');
    assert.equal(context.classify(15, 10), null);
});
