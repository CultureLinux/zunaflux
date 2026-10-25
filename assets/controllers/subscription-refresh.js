document.querySelectorAll('[data-subscription-refresh-form], [data-category-refresh-form], [data-all-subscriptions-refresh-form]').forEach(form => {
    const dialog = document.getElementById(form.dataset.subscriptionRefreshDialog);
    if (!(dialog instanceof HTMLDialogElement)) return;

    const log = dialog.querySelector('[data-subscription-refresh-log]');
    dialog.querySelector('[data-subscription-refresh-close]').addEventListener('click', () => dialog.close());
    form.addEventListener('submit', async event => {
        event.preventDefault();
        dialog.showModal();
        log.textContent = dialog.dataset.queued;
        try {
            const csrfToken = form.querySelector('input[name="_token"]')?.value;
            const response = await fetch(form.action, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken }, body: new FormData(form, event.submitter) });
            if (!response.ok) throw new Error();
            const { runId } = await response.json();
            const poll = async () => {
                const status = await fetch(form.dataset.statusUrl.replace('__run_id__', runId));
                if (!status.ok) throw new Error();
                const result = await status.json();
                const labels = {
                    pending: ['◌', dialog.dataset.pending, 'text-muted'],
                    running: ['↻', dialog.dataset.running, 'text-accent'],
                    collected: ['✓', dialog.dataset.collected, 'text-success'],
                    no_new_articles: ['–', dialog.dataset.empty, 'text-muted'],
                    error: ['✕', dialog.dataset.error, 'text-danger'],
                };
                log.replaceChildren();
                for (const entry of result.entries) {
                    const [icon, label, color] = labels[entry.status] || ['?', entry.status, 'text-muted'];
                    const row = document.createElement('div'); row.className = 'flex items-start gap-3 border-b border-line px-3 py-2 text-sm';
                    const marker = document.createElement('span'); marker.className = `${color} w-5 text-center font-bold`; marker.textContent = icon;
                    const text = document.createElement('span'); text.className = 'min-w-0 break-words';
                    const articles = entry.new_articles ? ` : ${dialog.dataset.newArticles.replace('%count%', entry.new_articles)}` : '';
                    text.textContent = `${entry.title || entry.canonical_url} — ${label}${articles}${entry.detail ? ` · ${entry.detail}` : ''}`;
                    row.append(marker, text); log.append(row);
                }
                if (!result.complete) window.setTimeout(poll, 1000);
            };
            await poll();
        } catch (_) {
            log.textContent = dialog.dataset.networkError;
        }
    });
});
