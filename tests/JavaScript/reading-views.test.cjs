const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const context = { document: { querySelector: () => null }, URLSearchParams };
vm.runInNewContext(fs.readFileSync('assets/controllers/article-list.js', 'utf8') + '\nthis.viewFromParams = viewFromParams; this.setViewParams = setViewParams;', context);

test('favorite and saved views include read articles and clear the previous view', () => {
    const params = new URLSearchParams('read=0&saved=1&subscription=source');
    context.setViewParams(params, 'favorite');
    assert.equal(params.toString(), 'subscription=source&favorite=1');
    assert.equal(context.viewFromParams(params), 'favorite');
    context.setViewParams(params, 'saved');
    assert.equal(params.toString(), 'subscription=source&saved=1');
    context.setViewParams(params, '0');
    assert.equal(params.toString(), 'subscription=source&read=0');
    assert.equal(context.viewFromParams(params), '0');
});

test('the reading menu exposes both personal views', () => {
    const template = fs.readFileSync('templates/article/index.html.twig', 'utf8');
    assert.match(template, /<option value="favorite">\{\{ "Favoris"\|trans \}\}<\/option>/);
    assert.match(template, /<option value="saved">\{\{ "À lire plus tard"\|trans \}\}<\/option>/);
});
