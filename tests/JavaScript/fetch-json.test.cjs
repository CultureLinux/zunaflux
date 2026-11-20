const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const load = (fetchImpl) => {
    const navigations = [];
    const source = fs.readFileSync('assets/reading/api.js', 'utf8').replace(/export class/g, 'class').replace(/export const/g, 'const');
    const context = {
        fetch: fetchImpl,
        location: { assign: (url) => navigations.push(url) },
        URL,
        window: { zunaflux: { t: (key) => key } },
    };
    vm.runInNewContext(source + '\nthis.fetchJson = fetchJson; this.ApiError = ApiError;', context);
    return { fetchJson: context.fetchJson, ApiError: context.ApiError, navigations };
};

const response = ({ ok = true, status = 200, redirected = false, url = 'http://localhost/api', type = 'application/json', body = {} } = {}) => ({
    ok, status, redirected, url,
    headers: { get: (name) => (name.toLowerCase() === 'content-type' ? type : null) },
    json: async () => { if (typeof body === 'string') throw new SyntaxError('Unexpected token <'); return body; },
});

test('returns the decoded body of a JSON answer and asks for JSON', async () => {
    let received;
    const { fetchJson } = load(async (url, options) => { received = options; return response({ body: { id: 1 } }); });
    assert.deepEqual(await fetchJson('/api/articles/1', { headers: { 'X-CSRF-Token': 'abc' } }), { id: 1 });
    assert.equal(received.headers.Accept, 'application/json');
    assert.equal(received.headers['X-CSRF-Token'], 'abc');
});

test('an expired session redirects to the login page instead of parsing HTML', async () => {
    const { fetchJson, navigations } = load(async () => response({ redirected: true, url: 'http://localhost/login', type: 'text/html', body: '<html>' }));
    await assert.rejects(fetchJson('/api/articles'), { message: 'Votre session a expiré. Reconnectez-vous puis réessayez.' });
    assert.deepEqual(navigations, ['/login']);
});

test('an HTML answer never leaks a JSON parse error', async () => {
    const { fetchJson } = load(async () => response({ type: 'text/html', body: '<html>' }));
    await assert.rejects(fetchJson('/api/articles', { fallbackMessage: 'Cet article est indisponible.' }), { message: 'Cet article est indisponible.' });
});

test('HTTP errors use the server message, then the fallback', async () => {
    const withMessage = load(async () => response({ ok: false, status: 422, body: { error: 'Action invalide.' } }));
    await assert.rejects(withMessage.fetchJson('/x'), (error) => error.message === 'Action invalide.' && error.status === 422);
    const withoutMessage = load(async () => response({ ok: false, status: 500, body: {} }));
    await assert.rejects(withoutMessage.fetchJson('/x', { fallbackMessage: 'Fallback.' }), { message: 'Fallback.' });
});

test('network failures are translated but aborts are re-thrown untouched', async () => {
    const offline = load(async () => { throw new TypeError('Failed to fetch'); });
    await assert.rejects(offline.fetchJson('/x'), { message: 'Connexion au serveur impossible. Vérifiez votre réseau.' });
    const aborted = load(async () => { const error = new Error('aborted'); error.name = 'AbortError'; throw error; });
    await assert.rejects(aborted.fetchJson('/x'), { name: 'AbortError' });
});
