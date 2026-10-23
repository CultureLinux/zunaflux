const classifyZenGesture = (dx, dy) => {
    if (Math.abs(dx) < 60 || Math.abs(dx) <= Math.abs(dy)) return null;
    return dx < 0 ? 'next' : 'previous';
};

const formatZenCount = (count, singular, plural) => (count === 1 ? singular : plural).replace('%count%', String(count));

const zen = document.querySelector('[data-zen-dialog]');
if (zen) {
    const messages = JSON.parse(zen.dataset.messages);
    const open = document.querySelector('[data-zen-open]');
    const count = document.querySelector('[data-zen-count]');
    const remaining = zen.querySelector('[data-zen-remaining]');
    const content = zen.querySelector('[data-zen-content]');
    const title = zen.querySelector('[data-zen-title]');
    const original = zen.querySelector('[data-zen-link]');
    const source = zen.querySelector('[data-zen-source]');
    const body = zen.querySelector('[data-zen-body]');
    const message = zen.querySelector('[data-zen-message]');
    const complete = zen.querySelector('[data-zen-complete]');
    const guide = zen.querySelector('[data-zen-guide]');
    const previous = zen.querySelector('[data-zen-previous]');
    const next = zen.querySelector('[data-zen-next]');
    const favorite = zen.querySelector('[data-zen-favorite]');
    const save = zen.querySelector('[data-zen-save]');
    const controls = [previous, next, favorite, save];
    let items = [], cursor = null, hasMore = false, index = -1, article = null, busy = false, changed = false, finished = false;
    const refreshCount = async () => {
        try {
            const response = await fetch('/api/statistics/counters', { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error();
            const data = await response.json();
            count.textContent = String(data.all.unread_count);
            remaining.textContent = formatZenCount(data.all.unread_count, messages.remainingOne, messages.remainingMany);
            open.setAttribute('aria-label', formatZenCount(data.all.unread_count, messages.openOne, messages.openMany));
        } catch (_) { count.textContent = '–'; remaining.textContent = messages.unavailable; }
    };
    const loadMore = async () => {
        const url = new URL('/api/articles', location.origin);
        url.searchParams.set('read', '0');
        if (cursor) url.searchParams.set('cursor', cursor);
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(messages.loadArticles);
        const page = await response.json();
        items.push(...page.items.filter(item => !items.some(existing => existing.id === item.id)));
        cursor = page.nextCursor;
        hasMore = page.hasMore;
    };
    const show = async () => {
        const response = await fetch(`/api/articles/${encodeURIComponent(items[index].id)}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(messages.loadArticle);
        article = await response.json();
        finished = false; complete.hidden = true; title.hidden = false; body.hidden = false; message.classList.remove('sr-only');
        title.textContent = article.title;
        original.hidden = true;
        try { const url = new URL(article.url); if (['http:', 'https:'].includes(url.protocol)) { original.href = url.href; original.hidden = false; } } catch (_) { /* Invalid links are not shown. */ }
        source.textContent = article.source || messages.untitled;
        body.innerHTML = article.safeHtml;
        content.scrollTop = 0;
        favorite.setAttribute('aria-pressed', String(article.isFavorite));
        save.setAttribute('aria-pressed', String(article.isSaved));
        favorite.textContent = article.isFavorite ? '★' : '☆';
        save.textContent = article.isSaved ? '◆' : '◇';
        previous.disabled = index <= 0;
        message.textContent = '';
    };
    const update = async changes => {
        const response = await fetch(`/api/articles/${encodeURIComponent(article.id)}/state`, {
            method: 'PATCH', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': article.csrfToken }, body: JSON.stringify(changes),
        });
        if (!response.ok) throw new Error(messages.updateError);
        Object.assign(article, await response.json());
        changed = true;
        if (!zen.open) document.dispatchEvent(new Event('zen-state-changed'));
    };
    const act = async (action, swipe = false) => {
        if (busy || !article || !guide.hidden) return;
        busy = true;
        controls.forEach(button => { button.disabled = true; });
        try {
            const previousIndex = index;
            const wasFinished = finished;
            if (action === 'next') {
                if (!article.isRead) await update({ isRead: true });
                if (index + 1 >= items.length && hasMore) await loadMore();
                if (index + 1 < items.length) { index++; await show(); }
                else { finished = true; complete.hidden = false; title.hidden = true; original.hidden = true; source.textContent = ''; body.hidden = true; message.classList.add('sr-only'); message.textContent = messages.complete; content.scrollTop = 0; }
            } else if (action === 'previous' && finished) { await show(); }
            else if (action === 'previous' && index > 0) { index--; await show(); }
            else if (action === 'favorite') { await update({ isFavorite: !article.isFavorite }); favorite.setAttribute('aria-pressed', String(article.isFavorite)); favorite.textContent = article.isFavorite ? '★' : '☆'; message.textContent = article.isFavorite ? messages.favorited : messages.unfavorited; }
            else if (action === 'save') { await update({ isSaved: true }); save.setAttribute('aria-pressed', 'true'); save.textContent = '◆'; message.textContent = messages.saved; }
            if (swipe && (index !== previousIndex || finished !== wasFinished) && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                content.animate?.([{ opacity: 0.7, transform: `translateX(${action === 'next' ? '12px' : '-12px'})` }, { opacity: 1, transform: 'translateX(0)' }], { duration: 200, easing: 'ease-out' });
            }
            if (action === 'next') await refreshCount();
        } catch (error) { message.textContent = error.message || messages.error; }
        finally { busy = false; controls.forEach(button => { button.disabled = finished; }); previous.disabled = !finished && index <= 0; }
    };
    open.addEventListener('click', async () => {
        if (busy) return;
        zen.showModal();
        refreshCount();
        guide.hidden = true;
        try { if (!localStorage.getItem('zunaflux-zen-guide')) guide.hidden = false; } catch (_) { guide.hidden = false; }
        items = []; cursor = null; hasMore = false; index = -1; article = null; changed = false; finished = false; complete.hidden = true; title.hidden = false; body.hidden = false; message.classList.remove('sr-only');
        title.textContent = messages.title; original.hidden = true; source.textContent = ''; body.replaceChildren(); message.textContent = messages.loading;
        busy = true; controls.forEach(button => { button.disabled = true; });
        try { await loadMore(); if (items.length) { index = 0; await show(); } else message.textContent = messages.empty; }
        catch (error) { message.textContent = error.message || messages.loadArticles; }
        finally { busy = false; controls.forEach(button => { button.disabled = !article; }); previous.disabled = index <= 0; }
    });
    zen.querySelector('[data-zen-close]').addEventListener('click', () => zen.close());
    zen.querySelector('[data-zen-help]').addEventListener('click', () => { guide.hidden = false; });
    zen.querySelector('[data-zen-guide-close]').addEventListener('click', () => { guide.hidden = true; try { localStorage.setItem('zunaflux-zen-guide', '1'); } catch (_) {} });
    zen.addEventListener('close', () => { if (changed) document.dispatchEvent(new Event('zen-state-changed')); refreshCount(); });
    previous.addEventListener('click', () => act('previous'));
    next.addEventListener('click', () => act('next'));
    favorite.addEventListener('click', () => act('favorite'));
    save.addEventListener('click', () => act('save'));
    let start = null;
    content.addEventListener('touchstart', event => { start = event.touches.length === 1 ? [event.touches[0].clientX, event.touches[0].clientY] : null; }, { passive: true });
    content.addEventListener('touchend', event => { if (!start || event.changedTouches.length !== 1) { start = null; return; } const touch = event.changedTouches[0]; const action = classifyZenGesture(touch.clientX - start[0], touch.clientY - start[1]); start = null; if (action) act(action, true); }, { passive: true });
    content.addEventListener('touchcancel', () => { start = null; });
    document.addEventListener('article-state-changed', refreshCount);
    refreshCount();
    const url = new URL(location.href);
    if (url.searchParams.get('zen') === '1') {
        url.searchParams.delete('zen');
        history.replaceState(null, '', url);
        open.click();
    }
}
