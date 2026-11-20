const fs = require('node:fs');
const vm = require('node:vm');
const readingSources = require('../../assets/reading/sources.js');
const { installReadingApi } = require('./reading-api.cjs');

// Compatibility bridge for legacy VM tests of the remaining controller code.
// Source/view tests import the real module directly instead of using this bridge.
// api.js and share.js are evaluated inside the context so they use its fetch() and navigator.
function evaluateArticleList(context, suffix = '') {
    const source = fs.readFileSync('assets/controllers/article-list.js', 'utf8');
    Object.assign(context, readingSources);
    installReadingApi(context);
    const share = fs.readFileSync('assets/reading/share.js', 'utf8').replace(/export const/g, 'const');
    vm.runInContext(`(function () {\n${share}\nthis.shareArticle = shareArticle;\n}).call(this);`, context);
    vm.runInContext(source.replace(/^import .* from '\.\.\/reading\/(?:sources|api|share)\.js';\n/gm, '') + suffix, context);
}

module.exports = { evaluateArticleList };
