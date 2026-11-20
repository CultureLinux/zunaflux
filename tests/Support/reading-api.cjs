const fs = require('node:fs');
const vm = require('node:vm');

/** Evaluates assets/reading/api.js inside a test context, so it calls that context's own fetch(). */
function installReadingApi(context) {
    vm.createContext(context);
    context.URL ??= URL;
    context.window ??= { zunaflux: { t: (key) => key } };
    const source = fs.readFileSync('assets/reading/api.js', 'utf8').replace(/export class/g, 'class').replace(/export const/g, 'const');
    // Wrapped in a function: api.js declares its own `t`, which would clash with the script under test.
    vm.runInContext(`(function () {\n${source}\nthis.fetchJson = fetchJson; this.sendJson = sendJson; this.fetchCounters = fetchCounters;\n}).call(this);`, context);
    return context;
}

/** A minimal Response double that looks like the JSON API. */
const jsonResponse = (body = {}, { ok = true, status = 200 } = {}) => ({
    ok,
    status,
    redirected: false,
    url: 'http://localhost/api',
    headers: { get: (name) => (name.toLowerCase() === 'content-type' ? 'application/json' : null) },
    json: async () => body,
});

module.exports = { installReadingApi, jsonResponse };
