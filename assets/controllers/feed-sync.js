document.querySelectorAll('[data-feed-sync-form]').forEach(form => {
    const dialog = document.querySelector('[data-feed-sync-dialog]');
    if (!(dialog instanceof HTMLDialogElement)) return;
    const log = dialog.querySelector('[data-feed-sync-log]');
    dialog.querySelector('[data-feed-sync-close]').addEventListener('click', () => dialog.close());
    form.addEventListener('submit', async event => {
        event.preventDefault();
        dialog.showModal(); log.textContent = 'Mise en file…';
        try {
            const csrfToken = form.querySelector('input[name="_token"]')?.value;
            const response = await fetch(form.action, {method: 'POST', headers: {Accept: 'application/json', 'X-CSRF-Token': csrfToken}, body: new FormData(form, event.submitter)});
            if (!response.ok) throw {stage: 'lancement', status: response.status};
            const {runId} = await response.json();
            const poll = async () => {
                const status = await fetch(`/admin/feed-sync/${runId}`);
                if (!status.ok) throw {stage: 'suivi', status: status.status};
                const result = await status.json();
                const labels = {pending: ['◌', 'En attente du worker', 'text-muted'], running: ['↻', 'Collecte en cours', 'text-accent'], collected: ['✓', 'Article(s) ajouté(s)', 'text-success'], no_new_articles: ['–', 'Aucun nouvel article', 'text-muted'], error: ['✕', 'Erreur', 'text-danger']};
                log.replaceChildren();
                for (const entry of result.entries) {
                    const [icon, status, color] = labels[entry.status] || ['?', entry.status, 'text-muted'];
                    const row = document.createElement('div'); row.className = 'flex items-start gap-3 border-b border-line px-3 py-2 text-sm';
                    const marker = document.createElement('span'); marker.className = `${color} w-5 text-center font-bold`; marker.textContent = icon;
                    const text = document.createElement('span'); text.className = 'min-w-0 break-words'; text.textContent = `${entry.title || entry.canonical_url} — ${status}${entry.new_articles ? ` : ${entry.new_articles} nouvel(s) article(s)` : ''}${entry.detail ? ` · ${entry.detail}` : ''}`;
                    row.append(marker, text); log.append(row);
                }
                if (!result.complete) window.setTimeout(poll, 1000);
            };
            await poll();
        } catch (error) {
            if (error && ['lancement', 'suivi'].includes(error.stage)) {
                log.textContent = `Le ${error.stage} a échoué (HTTP ${error.status}).`;
                return;
            }
            log.textContent = 'Impossible de joindre le serveur pour lancer ou suivre la synchronisation.';
        }
    });
});
