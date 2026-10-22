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

test('reading metadata uses the selected language for markers and dates', () => {
    const source = fs.readFileSync('assets/controllers/article-list.js', 'utf8');
    const context = { document: { querySelector: () => null }, URLSearchParams };
    vm.runInNewContext(source + '\nthis.readingMarkers = readingMarkers; this.formatReadingDate = formatReadingDate;', context);
    const labels = { unread: 'Ungelesen', favorite: 'Favorit', saved: 'Später lesen' };
    assert.deepEqual(Array.from(context.readingMarkers({ isRead: false, isFavorite: true, isSaved: false }, labels)), ['Ungelesen', 'Favorit']);
    const date = new Date('2026-10-20T13:25:00Z');
    assert.equal(context.formatReadingDate(date, 'de'), new Intl.DateTimeFormat('de', { dateStyle: 'medium', timeStyle: 'short' }).format(date));
    assert.equal(context.formatReadingDate(date, 'en'), new Intl.DateTimeFormat('en', { dateStyle: 'medium', timeStyle: 'short' }).format(date));
});
