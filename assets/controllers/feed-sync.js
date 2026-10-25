document.querySelectorAll('[data-feed-sync-form]').forEach(form => {
    const dialog = document.querySelector('[data-feed-sync-dialog]');
    if (!(dialog instanceof HTMLDialogElement)) return;
    const log = dialog.querySelector('[data-feed-sync-log]');
    dialog.querySelector('[data-feed-sync-close]').addEventListener('click', () => dialog.close());
    form.addEventListener('submit', async event => {
        event.preventDefault();
        dialog.showModal(); log.textContent = form.dataset.feedSyncQueued;
        try {
            const csrfToken = form.querySelector('input[name="_token"]')?.value;
            const response = await fetch(form.action, {method: 'POST', headers: {Accept: 'application/json', 'X-CSRF-Token': csrfToken}, body: new FormData(form, event.submitter)});
            if (!response.ok) throw {stage: 'lancement', status: response.status};
            const {runId} = await response.json();
            const poll = async () => {
                const status = await fetch(`/admin/feed-sync/${runId}`);
                if (!status.ok) throw {stage: 'suivi', status: status.status};
                const result = await status.json();
                const labels = {pending: ['◌', form.dataset.feedSyncStatusPending, 'text-muted'], running: ['↻', form.dataset.feedSyncStatusRunning, 'text-accent'], collected: ['✓', form.dataset.feedSyncStatusCollected, 'text-success'], no_new_articles: ['–', form.dataset.feedSyncStatusEmpty, 'text-muted'], error: ['✕', form.dataset.feedSyncStatusError, 'text-danger']};
                log.replaceChildren();
                for (const entry of result.entries) {
                    const [icon, status, color] = labels[entry.status] || ['?', entry.status, 'text-muted'];
                    const row = document.createElement('div'); row.className = 'flex items-start gap-3 border-b border-line px-3 py-2 text-sm';
                    const marker = document.createElement('span'); marker.className = `${color} w-5 text-center font-bold`; marker.textContent = icon;
                    const articles = entry.new_articles ? ` : ${form.dataset.feedSyncNewArticles.replace('%count%', entry.new_articles)}` : '';
                    const text = document.createElement('span'); text.className = 'min-w-0 break-words'; text.textContent = `${entry.title || entry.canonical_url} — ${status}${articles}${entry.detail ? ` · ${entry.detail}` : ''}`;
                    row.append(marker, text); log.append(row);
                }
                if (!result.complete) window.setTimeout(poll, 1000);
            };
            await poll();
        } catch (error) {
            if (error && ['lancement', 'suivi'].includes(error.stage)) {
                log.textContent = (error.stage === 'lancement' ? form.dataset.feedSyncStartError : form.dataset.feedSyncStatusErrorMessage).replace('%status%', error.status);
                return;
            }
            log.textContent = form.dataset.feedSyncNetworkError;
        }
    });
});
