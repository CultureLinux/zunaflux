const container = document.querySelector('[data-article-list]');

if (container) {
    const endpoint = container.dataset.articleList;
    const workspace = document.querySelector('.reading-workspace');
    const desktopLayout = window.matchMedia('(min-width: 1024px)');
    const dialog = document.querySelector('[data-article-dialog]');
    const dialogTitle = dialog?.querySelector('[data-article-title]');
    const dialogSource = dialog?.querySelector('[data-article-source]');
    const dialogSummary = dialog?.querySelector('[data-article-summary]');
    const dialogLinks = [...dialog.querySelectorAll('[data-article-link]')];
    const readButtons = [...dialog.querySelectorAll('[data-article-read]')];
    const favoriteButtons = [...dialog.querySelectorAll('[data-article-favorite]')];
    const savedButtons = [...dialog.querySelectorAll('[data-article-saved]')];
    const previousButtons = [...dialog.querySelectorAll('[data-article-previous]')];
    const nextButtons = [...dialog.querySelectorAll('[data-article-next]')];
    const dialogError = dialog.querySelector('[data-article-error]');
    const moreButton = document.querySelector('[data-article-more]');
    const sentinel = document.querySelector('[data-article-sentinel]');
    const actions = document.querySelector('[data-article-actions]');
    const selectedIds = new Set();
    const selectAllButton = actions.querySelector('[data-select-all]');
    const selectedCount = actions.querySelector('[data-selected-count]');
    const bulkError = document.querySelector('[data-bulk-error]');
    const sourceFilter = document.querySelector('[data-source-filter]');
    const sourceFilterLabel = document.querySelector('[data-source-filter-label]');
    let subscriptionFilter = new URLSearchParams(location.search).get('subscription') || '';
    let sourceName = '';
    const updateSourceFilter = () => {
        sourceFilter.hidden = !subscriptionFilter;
        sourceFilterLabel.textContent = sourceName ? `Flux : ${sourceName}` : 'Filtre sur un flux actif';
    };
    updateSourceFilter();
    const loadedItems = [];
    const updateSelection = () => {
        const selected = loadedItems.filter((item) => selectedIds.has(String(item.id)));
        for (const [key, property, target] of [['read', 'isRead', true], ['unread', 'isRead', false], ['favorite', 'isFavorite', true], ['unfavorite', 'isFavorite', false], ['save', 'isSaved', true], ['unsave', 'isSaved', false]]) {
            const button = actions.querySelector(`[data-bulk-${key}]`);
            button.hidden = !selected.some((item) => Boolean(item[property]) !== target);
            button.disabled = button.hidden;
        }
        selectAllButton.hidden = selectedIds.size === 0;
        const boxes = [...container.querySelectorAll('[data-article-select]')];
        const allSelected = boxes.length > 0 && boxes.every((box) => box.checked);
        selectAllButton.setAttribute('aria-pressed', String(allSelected));
        selectAllButton.textContent = allSelected ? 'Désélectionner les articles chargés' : 'Sélectionner les articles chargés';
        selectedCount.textContent = selectedIds.size ? `${selectedIds.size} sélectionné(s)` : '';
        selectAllButton.disabled = boxes.length === 0;
    };
    updateSelection();
    selectAllButton.addEventListener('click', () => {
        const boxes = [...container.querySelectorAll('[data-article-select]')];
        const select = boxes.some((box) => !box.checked);
        boxes.forEach((box) => { box.checked = select; if (select) selectedIds.add(box.dataset.articleSelect); else selectedIds.delete(box.dataset.articleSelect); });
        updateSelection();
    });
    const readMode = document.querySelector('[data-read-mode-default]')?.dataset.readModeDefault || 'on_preview';
    const searchForm = document.querySelector('[data-article-search]');
    const searchInput = searchForm?.querySelector('[name="q"]');
    const readFilter = document.querySelector('[data-read-filter]');
    const initialRead = new URLSearchParams(location.search).get('read');
    if (initialRead === '0' || initialRead === '1' || initialRead === '') readFilter.value = initialRead;
    const updateSources = () => {
        document.querySelectorAll('[data-reading-source]').forEach((row) => {
            const unread = Number(row.dataset.unread);
            const read = Number(row.dataset.read);
            row.hidden = (readFilter.value === '0' ? unread : readFilter.value === '1' ? read : unread + read) === 0;
            const link = row.querySelector('[data-source-link]');
            const url = new URL(link.href);
            url.searchParams.set('read', readFilter.value);
            link.href = url.href;
        });
    };
    updateSources();
    const savedFilters = document.querySelector('[data-saved-filters]');
    const savedFilterSelect = savedFilters?.querySelector('[data-saved-filter]');
    let nextCursor = null;
    let selectedArticle = null;
    let loading = false;
    let pageRequest = null;
    const updateUnreadCount = (item, isRead) => {
        if (!item || item.isRead === isRead) return;
        const badge = document.querySelector(`[data-unread-subscription="${CSS.escape(String(item.subscriptionId))}"]`);
        if (!badge) return;
        const count = Math.max(0, Number(badge.textContent) + (isRead ? -1 : 1));
        badge.textContent = String(count);
        badge.setAttribute('aria-label', `${count} articles non lus`);
        const row = badge.closest('[data-reading-source]');
        if (row) { row.dataset.unread = String(count); row.dataset.read = String(Math.max(0, Number(row.dataset.read) + (isRead ? 1 : -1))); updateSources(); }
    };

    const bulk = async (changes) => {
        const ids = [...selectedIds];
        if (!ids.length) {
            bulkError.textContent = 'Sélectionnez au moins un article.';
            bulkError.hidden = false;
            return;
        }
        const buttons = [...actions.querySelectorAll('button')];
        buttons.forEach((button) => { button.disabled = true; });
        bulkError.hidden = true;
        let processed = 0;
        try {
            for (let i = 0; i < ids.length; i += 100) {
                const batch = ids.slice(i, i + 100);
                const response = await fetch(`${endpoint}/bulk`, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': actions.dataset.csrfToken }, body: JSON.stringify({ ids: batch, ...changes }) });
                if (!response.ok) throw new Error();
                const result = await response.json();
                if (result.skipped) throw new Error();
                if (typeof changes.isRead === 'boolean') batch.forEach((id) => {
                    const item = loadedItems.find((entry) => String(entry.id) === String(id));
                    updateUnreadCount(item, changes.isRead);
                    if (item) item.isRead = changes.isRead;
                });
                processed += batch.length;
            }
            selectedIds.clear();
            await loadPage();
        } catch (_) {
            bulkError.textContent = `L’action a échoué après ${processed} article(s). Actualisez la liste avant de réessayer.`;
            bulkError.hidden = false;
        } finally {
            buttons.forEach((button) => { button.disabled = false; });
            updateSelection();
        }
    };

    actions?.querySelector('[data-bulk-read]').addEventListener('click', () => bulk({ isRead: true }));
    actions?.querySelector('[data-bulk-unread]').addEventListener('click', () => bulk({ isRead: false }));
    actions?.querySelector('[data-bulk-favorite]').addEventListener('click', () => bulk({ isFavorite: true }));
    actions?.querySelector('[data-bulk-unfavorite]').addEventListener('click', () => bulk({ isFavorite: false }));
    actions?.querySelector('[data-bulk-save]').addEventListener('click', () => bulk({ isSaved: true }));
    actions?.querySelector('[data-bulk-unsave]').addEventListener('click', () => bulk({ isSaved: false }));
    const setStateLabels = (article) => {
        for (const [buttons, active, on, off] of [
            [readButtons, article.isRead, 'Marquer non lu', 'Marquer lu'],
            [favoriteButtons, article.isFavorite, 'Retirer le favori', 'Ajouter aux favoris'],
            [savedButtons, article.isSaved, 'Retirer de la liste', 'Lire plus tard'],
        ]) {
            buttons.forEach((button) => {
                const label = active ? on : off;
                button.setAttribute('aria-label', label);
                button.setAttribute('aria-pressed', String(active));
                button.title = label;
            });
        }
    };
    const setActionDisabled = (disabled) => [...readButtons, ...favoriteButtons, ...savedButtons].forEach((button) => { button.disabled = disabled; });
    const updateState = (changes) => {
        const article = selectedArticle;
        return fetch(`${endpoint}/${article.id}/state`, {
            method: 'PATCH',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': article.csrfToken },
            body: JSON.stringify(changes),
        }).then((response) => {
            if (!response.ok) throw new Error('La mise à jour de cet article a échoué.');
            return response.json();
        }).then((state) => {
            const item = loadedItems.find((entry) => entry.id === article.id);
            updateUnreadCount(item, state.isRead);
            if (item) Object.assign(item, state);
            const row = container.querySelector(`[data-article-row-id="${CSS.escape(String(article.id))}"]`);
            if (row) {
                row.querySelector('[data-reading-state]').textContent = state.isRead ? 'Lu' : 'Non lu';
                row.querySelector('h2').classList.toggle('text-muted', state.isRead);
                row.querySelector('h2').classList.toggle('text-foreground', !state.isRead);
                row.classList.toggle('article-is-read', state.isRead);
            }
            if (selectedArticle?.id === article.id) {
                selectedArticle = { ...selectedArticle, ...state };
                setStateLabels(selectedArticle);
            }
        });
    };

    const openArticle = (id) => {
        fetch(`${endpoint}/${id}`, { headers: { Accept: 'application/json' } })
            .then((response) => {
                if (!response.ok) throw new Error('Cet article est indisponible.');

                return response.json();
            })
            .then((article) => {
                selectedArticle = article;
                const position = loadedItems.findIndex((item) => item.id === id);
                previousButtons.forEach((button) => { button.disabled = position <= 0; });
                nextButtons.forEach((button) => { button.disabled = position < 0 || position >= loadedItems.length - 1; });
                dialogError.hidden = true;
                setStateLabels(article);
                dialogTitle.textContent = article.title;
                dialogSource.textContent = article.source || 'Source sans titre';
                dialogSummary.innerHTML = article.safeHtml;
                dialogLinks.forEach((link) => { link.hidden = true; });
                try {
                    const url = new URL(article.url);
                    if (['http:', 'https:'].includes(url.protocol)) {
                        dialogLinks.forEach((link) => { link.href = url.href; link.hidden = false; });
                    }
                } catch (_) {
                    // A malformed or unsupported feed link is intentionally not exposed.
                }
                if (!dialog.open) { if (workspace && desktopLayout.matches) { dialog.show(); dialog.scrollIntoView({ block: 'start' }); } else dialog.showModal(); }
                if (readMode === 'on_preview' && !article.isRead) {
                    setActionDisabled(true);
                    updateState({ isRead: true }).catch(() => {
                        dialogError.textContent = 'Impossible de marquer cet article comme lu.';
                        dialogError.hidden = false;
                    }).finally(() => { setActionDisabled(false); });
                }

            })
            .catch((error) => {
                const message = document.createElement('p');
                message.className = 'border-l-2 border-danger bg-surface-muted p-4 text-danger';
                message.setAttribute('role', 'alert');
                message.textContent = error.message;
                container.prepend(message);
            });
    };

    const performAction = (changes) => {
        const articleId = selectedArticle.id;
        setActionDisabled(true);
        dialogError.hidden = true;
        updateState(changes).then(() => { if (dialog.open && selectedArticle?.id === articleId && !(workspace && desktopLayout.matches)) dialog.close(); }).catch(() => {
            dialogError.textContent = 'Impossible d’enregistrer cette action. Veuillez réessayer.';
            dialogError.hidden = false;
        }).finally(() => { setActionDisabled(false); });
    };
    readButtons.forEach((button) => button.addEventListener('click', () => performAction({ isRead: !selectedArticle.isRead })));
    favoriteButtons.forEach((button) => button.addEventListener('click', () => performAction({ isFavorite: !selectedArticle.isFavorite })));
    savedButtons.forEach((button) => button.addEventListener('click', () => performAction({ isSaved: !selectedArticle.isSaved })));
    dialogLinks.forEach((link) => link.addEventListener('click', () => dialog.close()));
    const moveArticle = (offset) => {
        const index = loadedItems.findIndex((item) => item.id === selectedArticle?.id);
        const target = loadedItems[index + offset];
        if (target) openArticle(target.id);
    };
    previousButtons.forEach((button) => button.addEventListener('click', () => moveArticle(-1)));
    nextButtons.forEach((button) => button.addEventListener('click', () => moveArticle(1)));
    document.addEventListener('keydown', (event) => {
        if (!dialog?.open || ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName)) return;
        if (event.key === 'ArrowLeft') moveArticle(-1);
        if (event.key === 'ArrowRight') moveArticle(1);
    });

    desktopLayout.addEventListener('change', () => { if (dialog.open) dialog.close(); });

    const loadPage = (cursor = null, append = false) => {
        if (loading && append) return Promise.resolve();
        if (pageRequest) pageRequest.abort();
        const request = new AbortController();
        pageRequest = request;
        loading = true;
        const url = new URL(endpoint, window.location.origin);
        if (cursor) url.searchParams.set('cursor', cursor);
        if (searchInput?.value.trim()) url.searchParams.set('q', searchInput.value.trim());
        if (readFilter.value !== '') url.searchParams.set('read', readFilter.value);
        for (const filter of ['favorite', 'saved']) { if (new URLSearchParams(location.search).get(filter) === '1') url.searchParams.set(filter, '1'); }
        if (subscriptionFilter) url.searchParams.set('subscription', subscriptionFilter);
        if (!append) { selectedIds.clear(); updateSelection(); }

        return fetch(url, { headers: { Accept: 'application/json' }, signal: request.signal })
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
                empty.className = 'border border-line bg-surface p-6 text-muted';
                empty.textContent = readFilter.value === '0' ? 'Aucun article non lu pour le moment.' : 'Aucun article ne correspond à vos critères.';
                moreButton.hidden = true;
                container.append(empty);
                updateSelection();

                return;
            }

            const list = append ? container.querySelector('ol') : document.createElement('ol');
            if (!append) list.className = 'grid grid-cols-1 gap-x-8 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4';

            items.forEach((item) => {
                const row = document.createElement('li');
                row.className = 'min-w-0 border-t border-line py-5';
                row.dataset.articleRowId = item.id;
                row.classList.toggle('article-is-read', item.isRead);
                const select = document.createElement('input');
                select.type = 'checkbox';
                select.dataset.articleSelect = item.id;
                select.className = 'mt-2 shrink-0';
                select.setAttribute('aria-label', `Sélectionner ${item.title}`);
                select.addEventListener('change', () => { select.checked ? selectedIds.add(String(item.id)) : selectedIds.delete(String(item.id)); updateSelection(); });
                const openButton = document.createElement('button');
                openButton.type = 'button';
                openButton.className = 'block w-full text-left';
                openButton.addEventListener('click', () => openArticle(item.id));
                const source = document.createElement('button');
                source.type = 'button';
                source.className = 'font-mono text-xs uppercase tracking-[.16em] text-accent hover:underline';
                source.textContent = item.source || 'Source sans titre';
                source.setAttribute('aria-label', `Filtrer sur ${source.textContent}`);
                source.addEventListener('click', () => {
                    subscriptionFilter = item.subscriptionId;
                    sourceName = item.source || 'Source sans titre';
                    const current = new URL(location.href);
                    current.searchParams.set('subscription', subscriptionFilter);
                    history.replaceState(null, '', current);
                    updateSourceFilter();
                    loadPage();
                });
                const title = document.createElement('h2');
                title.className = `mt-2 font-serif text-2xl ${item.isRead ? 'text-muted' : 'text-foreground'}`;
                title.textContent = item.title;
                const metadata = document.createElement('p');
                metadata.className = 'mt-2 flex flex-wrap items-center gap-2 font-mono text-xs text-muted';
                const timestamp = item.publishedAt || item.collectedAt;
                const date = new Date(timestamp);
                if (timestamp && !Number.isNaN(date.getTime())) {
                    const time = document.createElement('time');
                    time.dateTime = timestamp;
                    time.textContent = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium' }).format(date);
                    metadata.append(time);
                }
                const status = document.createElement('span');
                status.dataset.readingState = '';
                status.textContent = item.isRead ? 'Lu' : 'Non lu';
                metadata.append(status);
                openButton.append(title);

                if (item.summary) {
                    const summary = document.createElement('p');
                    summary.className = 'mt-2 line-clamp-2 text-sm leading-6 text-muted';
                    summary.textContent = item.summary;
                    openButton.append(summary);
                }

                const titleLine = document.createElement('div');
                titleLine.className = 'flex items-start gap-3';
                titleLine.append(openButton, select);
                row.append(source, metadata, titleLine);
                list.append(row);
            });

            if (!append) container.append(list);
            moreButton.hidden = !hasMore;
            updateSelection();
        })
        .catch((error) => {
            if (error.name === 'AbortError') return;
            const message = document.createElement('p');
            message.className = 'border-l-2 border-danger bg-surface-muted p-4 text-danger';
            message.setAttribute('role', 'alert');
            message.textContent = error.message;
            container.replaceChildren(message);
        }).finally(() => { if (pageRequest === request) { loading = false; pageRequest = null; } });
    };

    moreButton?.addEventListener('click', () => {
        moreButton.disabled = true;
        loadPage(nextCursor, true).finally(() => { moreButton.disabled = false; });
    });
    let searchTimer;
    searchInput?.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => loadPage(), 300);
    });
    searchForm?.addEventListener('submit', (event) => { event.preventDefault(); clearTimeout(searchTimer); loadPage(); });
    document.querySelector('[data-clear-source]').addEventListener('click', () => { subscriptionFilter = ''; sourceName = ''; const current = new URL(location.href); current.searchParams.delete('subscription'); history.replaceState(null, '', current); updateSourceFilter(); loadPage(); });
    readFilter.addEventListener('change', () => { const url = new URL(location.href); url.searchParams.set('read', readFilter.value); history.replaceState(null, '', url); updateSources(); loadPage(); });
    const savedHeaders = { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': savedFilters?.dataset.csrfToken };
    fetch('/api/saved-filters', { headers: { Accept: 'application/json' } }).then((r) => r.ok ? r.json() : []).then((filters) => filters.forEach((filter) => { const option = document.createElement('option'); option.value = JSON.stringify(filter.filters); option.textContent = filter.name; savedFilterSelect?.append(option); })).catch(() => {});
    savedFilterSelect?.addEventListener('change', () => { if (!savedFilterSelect.value) return; const filters = JSON.parse(savedFilterSelect.value); if (typeof filters.q === 'string' && searchInput) searchInput.value = filters.q; readFilter.value = ['0', '1', ''].includes(String(filters.read ?? '0')) ? String(filters.read ?? '0') : '0'; updateSources(); subscriptionFilter = typeof filters.subscription === 'string' ? filters.subscription : ''; sourceName = ''; updateSourceFilter(); loadPage(); history.replaceState(null, '', `${location.pathname}?${new URLSearchParams(filters)}`); });
    savedFilters?.querySelector('[data-save-filter]')?.addEventListener('click', () => { const name = window.prompt('Nom du filtre'); if (!name) return; fetch('/api/saved-filters', { method: 'POST', headers: savedHeaders, body: JSON.stringify({ name, filters: { q: searchInput?.value.trim() || '', read: readFilter.value, subscription: subscriptionFilter } }) }).then((r) => { if (!r.ok) throw new Error(); return r.json(); }).then(() => location.reload()).catch(() => window.alert('Impossible d’enregistrer ce filtre.')); });

    if (sentinel && 'IntersectionObserver' in window) {
        new IntersectionObserver(([entry]) => {
            if (entry.isIntersecting && nextCursor) loadPage(nextCursor, true);
        }, { rootMargin: '240px' }).observe(sentinel);
    }

    loadPage();
}
