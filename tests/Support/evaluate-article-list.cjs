const fs = require('node:fs');
const vm = require('node:vm');
const readingSources = require('../../assets/reading/sources.js');

// Compatibility bridge for legacy VM tests of the remaining controller code.
// Source/view tests import the real module directly instead of using this bridge.
function evaluateArticleList(context, suffix = '') {
    const source = fs.readFileSync('assets/controllers/article-list.js', 'utf8');
    Object.assign(context, readingSources);
    vm.runInNewContext(source.replace(/^import .* from '\.\.\/reading\/sources\.js';\n/, '') + suffix, context);
}

module.exports = { evaluateArticleList };
