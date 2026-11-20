const t = (key, params = {}) => (typeof window !== 'undefined' && window.zunaflux?.t ? window.zunaflux.t(key, params) : Object.entries(params).reduce((text, [name, value]) => text.replace(`%${name}%`, String(value)), key));

export class ApiError extends Error {
    constructor(message, status = 0) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
    }
}

/**
 * fetch() for the JSON API. Resolves with the decoded body; rejects with an error whose message
 * is safe to show: an expired session (redirect to the login page, which answers 200 with HTML)
 * and any non-JSON answer never surface as "Unexpected token <". Aborts are re-thrown untouched.
 */
export const fetchJson = async (url, { fallbackMessage, headers = {}, ...options } = {}) => {
    let response;
    try {
        response = await fetch(url, { ...options, headers: { Accept: 'application/json', ...headers } });
    } catch (error) {
        if (error?.name === 'AbortError') throw error;
        throw new ApiError(fallbackMessage || t('Connexion au serveur impossible. Vérifiez votre réseau.'));
    }
    if (response.redirected && new URL(response.url).pathname === '/login') {
        if (typeof location !== 'undefined') location.assign('/login');
        throw new ApiError(t('Votre session a expiré. Reconnectez-vous puis réessayez.'), response.status);
    }
    const isJson = (response.headers?.get('content-type') || '').includes('json');
    const data = isJson ? await response.json().catch(() => null) : null;
    if (!response.ok || data === null) {
        const serverMessage = isJson && typeof data?.error === 'string' ? data.error : '';
        throw new ApiError(serverMessage || fallbackMessage || t('Le serveur a renvoyé une réponse inattendue.'), response.status);
    }

    return data;
};

export const articlesEndpoint = '/api/articles';

export const fetchCounters = () => fetchJson('/api/statistics/counters');

/** Sends a JSON body with the CSRF header the API expects. */
export const sendJson = (url, method, body, csrfToken, options = {}) => fetchJson(url, {
    ...options,
    method,
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
    body: JSON.stringify(body),
});
