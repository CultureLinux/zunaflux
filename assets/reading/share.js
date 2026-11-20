/** Shares an article's public URL: native share sheet first, clipboard second. Resolves 'shared', 'copied' or false. */
export const shareArticle = (article) => {
    try {
        const url = new URL(article.url);
        if (!['http:', 'https:'].includes(url.protocol)) return Promise.resolve(false);
        if (typeof navigator !== 'undefined' && typeof navigator.share === 'function') return navigator.share({ title: article.title, url: url.href }).then(() => 'shared').catch(() => false);
        if (typeof navigator !== 'undefined' && typeof navigator.clipboard?.writeText === 'function') return navigator.clipboard.writeText(url.href).then(() => 'copied').catch(() => false);
        return Promise.resolve(false);
    } catch (_) {
        return Promise.resolve(false);
    }
};
