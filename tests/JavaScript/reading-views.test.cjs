const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const context = require('../../assets/reading/sources.js');


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

test('an absent or invalid read parameter defaults to unread while personal views take priority', () => {
    for (const query of ['', 'read=invalid', 'read=2']) {
        assert.equal(context.viewFromParams(new URLSearchParams(query)), '0');
    }
    assert.equal(context.viewFromParams(new URLSearchParams('read=')), '');
    assert.equal(context.viewFromParams(new URLSearchParams('read=1&saved=1')), 'saved');
    assert.equal(context.viewFromParams(new URLSearchParams('saved=1&favorite=1')), 'favorite');
});

test('switching views preserves search, category and pagination parameters', () => {
    const params = new URLSearchParams('q=RSS&category=science&cursor=page2&read=1&favorite=1&saved=1');
    context.setViewParams(params, '');
    assert.equal(params.get('q'), 'RSS');
    assert.equal(params.get('category'), 'science');
    assert.equal(params.get('cursor'), 'page2');
    assert.equal(params.get('read'), '');
    assert.equal(params.has('favorite'), false);
    assert.equal(params.has('saved'), false);
});
