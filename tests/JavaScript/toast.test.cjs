const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function createToast(classes) {
    const handlers = {};
    return {
        handlers,
        classList: { contains: (name) => classes.includes(name), add() {} },
        addEventListener(event, handler) { handlers[event] = handler; },
        querySelector: () => null,
        remove() { this.removed = true; },
        removed: false,
    };
}

function run(toasts) {
    const timers = [];
    const stack = { querySelectorAll: () => toasts, append() {} };
    const context = {
        document: { querySelector: () => stack },
        window: { addEventListener() {}, matchMedia: () => ({ matches: true }) },
        setTimeout: (fn, delay) => { timers.push({ fn, delay, cleared: false }); return timers.length - 1; },
        clearTimeout: (id) => { if (timers[id]) timers[id].cleared = true; },
    };
    vm.runInNewContext(fs.readFileSync('assets/toast.js', 'utf8'), context);
    return timers;
}

test('error toasts stay until dismissed', () => {
    const timers = run([createToast(['toast', 'toast--error'])]);
    assert.equal(timers.length, 0);
});

test('success toasts expire after six seconds but pause while hovered or focused', () => {
    const toast = createToast(['toast', 'toast--success']);
    const timers = run([toast]);
    assert.equal(timers.length, 1);
    assert.equal(timers[0].delay, 6000);

    toast.handlers.mouseenter();
    assert.equal(timers[0].cleared, true);
    toast.handlers.mouseleave();
    assert.equal(timers.length, 2);
    toast.handlers.focusin();
    assert.equal(timers[1].cleared, true);
});
