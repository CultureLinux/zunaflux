const t = (key, params = {}) => (typeof window !== 'undefined' && window.zunaflux?.t ? window.zunaflux.t(key, params) : Object.entries(params).reduce((text, [name, value]) => text.replace(`%${name}%`, String(value)), key));

const defaultMastodonStatus = (article) => {
    const title = String(article?.title || '').trim();
    try {
        const url = new URL(article?.url);
        return ['http:', 'https:'].includes(url.protocol) ? [title, url.href].filter(Boolean).join('\n') : title;
    } catch (_) {
        return title;
    }
};

const hasDirectRecipient = (status) => /@[\p{L}\p{N}_.-]+(?:@[\p{L}\p{N}.-]+)?/u.test(status);

const mastodonPublicationError = (response, payload) => {
    if (response.redirected && new URL(response.url).pathname === '/login') {
        return t('Votre session a expiré. Reconnectez-vous puis réessayez.');
    }

    return payload.error || t('La publication Mastodon a échoué.');
};

const dialog = document.querySelector('[data-mastodon-share-dialog]');

if (dialog) {
    const form = dialog.querySelector('[data-mastodon-share-form]');
    const status = dialog.querySelector('[data-mastodon-share-status]');
    const visibility = dialog.querySelector('[data-mastodon-share-visibility]');
    const count = dialog.querySelector('[data-mastodon-share-count]');
    const directHelp = dialog.querySelector('[data-mastodon-share-direct-help]');
    const error = dialog.querySelector('[data-mastodon-share-error]');
    const success = dialog.querySelector('[data-mastodon-share-success]');
    const link = dialog.querySelector('[data-mastodon-share-link]');
    const submit = dialog.querySelector('[data-mastodon-share-submit]');
    let article = null;

    const updateCount = () => { count.textContent = `${[...status.value].length} / 500`; };
    const setError = (message) => { error.textContent = message; error.hidden = !message; };
    const updateVisibilityHelp = () => { directHelp.hidden = visibility.value !== 'direct'; };
    const setPending = (pending) => { submit.disabled = pending; submit.textContent = pending ? t('Publication…') : t('Publier'); };
    const open = (selectedArticle) => {
        article = selectedArticle;
        status.value = defaultMastodonStatus(article);
        visibility.value = 'public';
        submit.hidden = false;
        setError(''); success.hidden = true; link.removeAttribute('href');
        updateCount(); updateVisibilityHelp(); dialog.showModal(); status.focus();
    };

    status.addEventListener('input', updateCount);
    visibility.addEventListener('change', updateVisibilityHelp);
    dialog.querySelectorAll('[data-mastodon-share-close], [data-mastodon-share-cancel]').forEach((button) => button.addEventListener('click', () => dialog.close()));
    window.addEventListener('zunaflux:mastodon-share', (event) => open(event.detail));
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!article || submit.disabled) return;
        const length = [...status.value.trim()].length;
        if (length < 1 || length > 500) {
            setError(t('Le message Mastodon doit contenir entre 1 et 500 caractères.'));
            return;
        }
        if ('direct' === visibility.value && !hasDirectRecipient(status.value)) {
            setError(t('Un message direct doit mentionner au moins un destinataire.'));
            return;
        }
        setPending(true); setError(''); success.hidden = true;
        try {
            const response = await fetch(`/api/articles/${encodeURIComponent(article.id)}/mastodon-status`, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': article.mastodonCsrfToken },
                body: JSON.stringify({ status: status.value, visibility: visibility.value }),
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || typeof payload.url !== 'string') throw new Error(mastodonPublicationError(response, payload));
            const url = new URL(payload.url);
            if (!['http:', 'https:'].includes(url.protocol)) throw new Error(t('Mastodon a renvoyé une URL invalide.'));
            link.href = url.href; success.hidden = false; submit.hidden = true;
        } catch (exception) {
            setError(exception.message || t('La publication Mastodon a échoué.'));
        } finally {
            setPending(false);
        }
    });
}
