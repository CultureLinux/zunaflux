const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('reading metadata shows the time and personal markers without a read label', () => {
    const source = fs.readFileSync('assets/controllers/article-list.js', 'utf8');
    const context = { document: { querySelector: () => null }, URLSearchParams };
    vm.runInNewContext(source + '\nthis.readingMarkers = readingMarkers;', context);
    assert.deepEqual(Array.from(context.readingMarkers({ isRead: true, isFavorite: true, isSaved: true })), ['Favori', 'À lire plus tard']);
    assert.deepEqual(Array.from(context.readingMarkers({ isRead: false, isFavorite: false, isSaved: false })), ['Non lu']);
    assert.match(source, /dateStyle: 'medium', timeStyle: 'short'/);
});
