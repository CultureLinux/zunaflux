document.querySelectorAll('[data-feed-retry-form]').forEach(form => {
    const dialog = form.nextElementSibling;
    if (!(dialog instanceof HTMLDialogElement)) return;

    const log = dialog.querySelector('[data-feed-retry-log]');
    const submit = form.querySelector('button[type="submit"], button:not([type])');
    dialog.querySelectorAll('[data-feed-retry-close]').forEach(button => button.addEventListener('click', () => dialog.close()));

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (submit.disabled) return;
        dialog.showModal();
        log.textContent = dialog.dataset.loading;
        submit.disabled = true;
        try {
            const response = await fetch(form.action, { method: 'POST', headers: { Accept: 'application/json' }, body: new FormData(form) });
            if (!response.ok) throw new Error();
            const result = await response.json();
            if (!Array.isArray(result.logs)) throw new Error();
            log.textContent = result.logs.join('\n');
            if (result.success === true) {
                dialog.close();
                form.closest('li')?.remove();
                window.dispatchEvent(new CustomEvent('zunaflux:toast', { detail: { type: 'success', message: dialog.dataset.success } }));
            }
        } catch (_) {
            log.textContent = dialog.dataset.error;
        } finally {
            submit.disabled = false;
        }
    });
});
