const container = document.querySelector('[data-article-list]');

if (container) {
    const endpoint = container.dataset.articleList;
    const dialog = document.querySelector('[data-article-dialog]');
    const dialogTitle = dialog?.querySelector('[data-article-title]');
    const dialogSource = dialog?.querySelector('[data-article-source]');
    const dialogSummary = dialog?.querySelector('[data-article-summary]');
    const dialogLink = dialog?.querySelector('[data-article-link]');
    const readButton = dialog?.querySelector('[data-article-read]');
    const favoriteButton = dialog?.querySelector('[data-article-favorite]');
    const savedButton = dialog?.querySelector('[data-article-saved]');
    const previousButton = dialog?.querySelector('[data-article-previous]');
    const nextButton = dialog?.querySelector('[data-article-next]');
    const moreButton = document.querySelector('[data-article-more]');
    const sentinel = document.querySelector('[data-article-sentinel]');
    const actions = document.querySelector('[data-article-actions]');
    const selectedIds = new Set();
    const readMode = actions?.querySelector('[data-read-mode]');
    const searchForm = document.querySelector('[data-article-search]');
    const searchInput = searchForm?.querySelector('[name="q"]');
    const savedFilters = document.querySelector('[data-saved-filters]');
    const savedFilterSelect = savedFilters?.querySelector('[data-saved-filter]');
    let nextCursor = null;
    let selectedArticle = null;
    const loadedItems = [];
    let loading = false;
    if (readMode) {
        readMode.value = document.querySelector('[data-read-mode-default]')?.dataset.readModeDefault || 'on_preview';
    }

    const bulk = (changes) => fetch(`${endpoint}/bulk`, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': actions.dataset.csrfToken }, body: JSON.stringify({ ids: [...selectedIds], ...changes }) }).then((response) => {
        if (!response.ok) throw new Error('L’action groupée a échoué.');
        selectedIds.clear();
        return loadPage();
    });

    actions?.querySelector('[data-bulk-read]').addEventListener('click', () => bulk({ isRead: true }));
    actions?.querySelector('[data-bulk-unread]').addEventListener('click', () => bulk({ isRead: false }));
    actions?.querySelector('[data-bulk-favorite]').addEventListener('click', () => bulk({ isFavorite: true }));
    actions?.querySelector('[data-bulk-unfavorite]').addEventListener('click', () => bulk({ isFavorite: false }));
    actions?.querySelector('[data-bulk-save]').addEventListener('click', () => bulk({ isSaved: true }));
    actions?.querySelector('[data-bulk-unsave]').addEventListener('click', () => bulk({ isSaved: false }));
    actions?.querySelector('[data-mark-before-submit]').addEventListener('click', () => {
        const before = actions.querySelector('[data-mark-before]').value;
        if (!before) return;
        if (!window.confirm(`Marquer comme lus tous les articles antérieurs au ${before} ?`)) return;
        fetch(`${endpoint}/mark-before`, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': actions.dataset.csrfToken }, body: JSON.stringify({ before }) }).then(() => loadPage());
    });

    const updateState = (changes) => fetch(`${endpoint}/${selectedArticle.id}/state`, {
        method: 'PATCH',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': selectedArticle.csrfToken },
        body: JSON.stringify(changes),
    }).then((response) => {
        if (!response.ok) throw new Error('La mise à jour de cet article a échoué.');

        return response.json();
    }).then((state) => {
        selectedArticle = { ...selectedArticle, ...state };
        readButton.textContent = state.isRead ? 'Marquer non lu' : 'Marquer lu';
        favoriteButton.textContent = state.isFavorite ? 'Retirer le favori' : 'Ajouter aux favoris';
        savedButton.textContent = state.isSaved ? 'Retirer de la liste' : 'Lire plus tard';
    });

    const openArticle = (id) => {
        fetch(`${endpoint}/${id}`, { headers: { Accept: 'application/json' } })
            .then((response) => {
                if (!response.ok) throw new Error('Cet article est indisponible.');

                return response.json();
            })
            .then((article) => {
                selectedArticle = article;
                const position = loadedItems.findIndex((item) => item.id === id);
                previousButton.disabled = position <= 0;
                nextButton.disabled = position < 0 || position >= loadedItems.length - 1;
                dialogTitle.textContent = article.title;
                dialogSource.textContent = article.source || 'Source sans titre';
                dialogSummary.textContent = article.summary || article.content || 'Aucun résumé disponible.';
                dialogLink.hidden = true;
                try {
                    const url = new URL(article.url);
                    if (['http:', 'https:'].includes(url.protocol)) {
                        dialogLink.href = url.href;
                        dialogLink.hidden = false;
                    }
                } catch (_) {
                    // A malformed or unsupported feed link is intentionally not exposed.
                }
                if (readMode?.value === 'on_preview') updateState({ isRead: true }).catch(() => {});
                dialog.showModal();
            })
            .catch((error) => {
                const message = document.createElement('p');
                message.className = 'border-l-2 border-orange-400 bg-orange-300/10 p-4 text-orange-100';
                message.setAttribute('role', 'alert');
                message.textContent = error.message;
                container.prepend(message);
            });
    };

    readButton?.addEventListener('click', () => updateState({ isRead: !selectedArticle.isRead }));
    favoriteButton?.addEventListener('click', () => updateState({ isFavorite: !selectedArticle.isFavorite }));
    savedButton?.addEventListener('click', () => updateState({ isSaved: !selectedArticle.isSaved }));
    const moveArticle = (offset) => {
        const index = loadedItems.findIndex((item) => item.id === selectedArticle?.id);
        const target = loadedItems[index + offset];
        if (target) openArticle(target.id);
    };
    previousButton?.addEventListener('click', () => moveArticle(-1));
    nextButton?.addEventListener('click', () => moveArticle(1));
    document.addEventListener('keydown', (event) => {
        if (!dialog?.open || ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName)) return;
        if (event.key === 'ArrowLeft') moveArticle(-1);
        if (event.key === 'ArrowRight') moveArticle(1);
    });

    const loadPage = (cursor = null, append = false) => {
        if (loading) return Promise.resolve();
        loading = true;
        const url = new URL(endpoint, window.location.origin);
        if (cursor) url.searchParams.set('cursor', cursor);
        if (searchInput?.value.trim()) url.searchParams.set('q', searchInput.value.trim());

        return fetch(url, { headers: { Accept: 'application/json' } })
        .then((response) => {
            if (!response.ok) throw new Error('La liste des articles est indisponible.');

            return response.json();
        })
        .then(({ items, nextCursor: receivedCursor, hasMore }) => {
            if (!append) loadedItems.length = 0;
            loadedItems.push(...items);
            nextCursor = receivedCursor;
            if (!append) container.replaceChildren();

            if (items.length === 0) {
                const empty = document.createElement('p');
                empty.className = 'border border-slate-800 bg-slate-900/60 p-6 text-slate-400';
                empty.textContent = 'Aucun article dans vos abonnements actifs pour le moment.';
                container.append(empty);

                return;
            }

            const list = append ? container.querySelector('ol') : document.createElement('ol');
            if (!append) list.className = 'divide-y divide-slate-800 border-y border-slate-800';

            items.forEach((item) => {
                const row = document.createElement('li');
                row.className = 'py-5';
                const select = document.createElement('input');
                select.type = 'checkbox';
                select.className = 'mr-3';
                select.setAttribute('aria-label', `Sélectionner ${item.title}`);
                select.addEventListener('change', () => { select.checked ? selectedIds.add(item.id) : selectedIds.delete(item.id); });
                const openButton = document.createElement('button');
                openButton.type = 'button';
                openButton.className = 'block w-full text-left';
                openButton.addEventListener('click', () => openArticle(item.id));
                const source = document.createElement('p');
                source.className = 'font-mono text-xs uppercase tracking-[.16em] text-cyan-300';
                source.textContent = item.source || 'Source sans titre';
                const title = document.createElement('h2');
                title.className = `mt-2 font-serif text-2xl ${item.isRead ? 'text-slate-400' : 'text-white'}`;
                title.textContent = item.title;
                openButton.append(source, title);

                if (item.summary) {
                    const summary = document.createElement('p');
                    summary.className = 'mt-2 line-clamp-2 text-sm leading-6 text-slate-400';
                    summary.textContent = item.summary;
                    openButton.append(summary);
                }

                row.append(select, openButton);
                list.append(row);
            });

            if (!append) container.append(list);
            moreButton.hidden = !hasMore;
        })
        .catch((error) => {
            const message = document.createElement('p');
            message.className = 'border-l-2 border-orange-400 bg-orange-300/10 p-4 text-orange-100';
            message.setAttribute('role', 'alert');
            message.textContent = error.message;
            container.replaceChildren(message);
        }).finally(() => { loading = false; });
    };

    moreButton?.addEventListener('click', () => {
        moreButton.disabled = true;
        loadPage(nextCursor, true).finally(() => { moreButton.disabled = false; });
    });
    searchForm?.addEventListener('submit', (event) => { event.preventDefault(); loadPage(); });
    const savedHeaders = { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': savedFilters?.dataset.csrfToken };
    fetch('/api/saved-filters', { headers: { Accept: 'application/json' } }).then((r) => r.ok ? r.json() : []).then((filters) => filters.forEach((filter) => { const option = document.createElement('option'); option.value = JSON.stringify(filter.filters); option.textContent = filter.name; savedFilterSelect?.append(option); })).catch(() => {});
    savedFilterSelect?.addEventListener('change', () => { if (!savedFilterSelect.value) return; const filters = JSON.parse(savedFilterSelect.value); if (typeof filters.q === 'string' && searchInput) searchInput.value = filters.q; loadPage(); history.replaceState(null, '', `${location.pathname}?${new URLSearchParams(filters)}`); });
    savedFilters?.querySelector('[data-save-filter]')?.addEventListener('click', () => { const name = window.prompt('Nom du filtre'); if (!name) return; fetch('/api/saved-filters', { method: 'POST', headers: savedHeaders, body: JSON.stringify({ name, filters: { q: searchInput?.value.trim() || '' } }) }).then((r) => { if (!r.ok) throw new Error(); return r.json(); }).then(() => location.reload()).catch(() => window.alert('Impossible d’enregistrer ce filtre.')); });

    if (sentinel && 'IntersectionObserver' in window) {
        new IntersectionObserver(([entry]) => {
            if (entry.isIntersecting && nextCursor) loadPage(nextCursor, true);
        }, { rootMargin: '240px' }).observe(sentinel);
    }

    loadPage();
}
