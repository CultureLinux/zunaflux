document.querySelectorAll('[data-feed-retry-form]').forEach(form => {
    const dialog = form.nextElementSibling;
    if (!(dialog instanceof HTMLDialogElement)) return;

    const log = dialog.querySelector('[data-feed-retry-log]');
    const submit = form.querySelector('button[type="submit"], button:not([type])');
    dialog.querySelectorAll('[data-feed-retry-close]').forEach(button => button.addEventListener('click', () => dialog.close()));

    const labels = {
        pending: dialog.dataset.labelPending,
        running: dialog.dataset.labelRunning,
        collected: dialog.dataset.labelCollected,
        no_new_articles: dialog.dataset.labelEmpty,
        error: dialog.dataset.labelError,
    };
    const render = entries => entries.map(entry => {
        const articles = entry.new_articles > 0 ? ` : ${dialog.dataset.newArticles.replace('%count%', entry.new_articles)}` : '';
        return `${entry.title || entry.canonical_url} — ${labels[entry.status] || entry.status}${articles}${entry.detail ? ` · ${entry.detail}` : ''}`;
    }).join('\n');

    const succeeded = () => {
        dialog.close();
        form.closest('li')?.remove();
        window.dispatchEvent(new CustomEvent('zunaflux:toast', { detail: { type: 'success', message: dialog.dataset.success } }));
    };

    // The collection runs in the worker: poll its sync run until every entry is settled.
    const follow = async runId => {
        for (;;) {
            const response = await fetch(dialog.dataset.statusUrl.replace('__run_id__', runId), { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error();
            const result = await response.json();
            if (!Array.isArray(result.entries)) throw new Error();
            log.textContent = render(result.entries);
            if (result.complete) return result.entries.length > 0 && result.entries.every(entry => ['collected', 'no_new_articles'].includes(entry.status));
            if (dialog.open === false) return null;
            await new Promise(resolve => window.setTimeout(resolve, 1000));
        }
    };

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (submit.disabled) return;
        dialog.showModal();
        log.textContent = dialog.dataset.loading;
        submit.disabled = true;
        try {
            const response = await fetch(form.action, { method: 'POST', headers: { Accept: 'application/json' }, body: new FormData(form) });
            if (!response.ok) throw new Error();
            const { runId } = await response.json();
            if (typeof runId !== 'string') throw new Error();
            if (await follow(runId) === true) succeeded();
        } catch (_) {
            log.textContent = dialog.dataset.error;
        } finally {
            submit.disabled = false;
        }
    });
});
