// Client-side translations. The French source string is the key, exactly as in Twig; the
// server renders the translated values for the keys listed in _js_messages.html.twig.
(() => {
    let messages = null;
    const load = () => {
        if (messages) return messages;
        try { messages = JSON.parse(document.getElementById('zunaflux-messages')?.textContent || '{}'); } catch (_) { messages = {}; }
        return messages;
    };
    window.zunaflux = window.zunaflux || {};
    window.zunaflux.t = (key, params = {}) => Object.entries(params).reduce((text, [name, value]) => text.replaceAll(`%${name}%`, String(value)), load()[key] ?? key);
})();
