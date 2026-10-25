(() => {
    const prompt = document.querySelector('[data-pwa-install]');
    if (!prompt || !/Android|iPhone|iPad|iPod/i.test(navigator.userAgent) || window.matchMedia('(display-mode: standalone)').matches || navigator.standalone) return;
    const action = prompt.querySelector('[data-pwa-install-action]');
    const dismiss = prompt.querySelector('[data-pwa-install-dismiss]');
    const help = prompt.querySelector('[data-pwa-install-help]');
    let deferred;
    const dismissDuration = 30 * 24 * 60 * 60 * 1000;
    let dismissed = false;
    try {
        const dismissedAt = Number(localStorage.getItem('zunaflux-install-dismissed-at'));
        dismissed = Number.isFinite(dismissedAt) && dismissedAt > 0 && Date.now() - dismissedAt < dismissDuration;
    } catch (_) {}
    dismiss.addEventListener('click', () => {
        prompt.hidden = true;
        dismissed = true;
        try { localStorage.setItem('zunaflux-install-dismissed-at', String(Date.now())); } catch (_) {}
    });
    window.addEventListener('beforeinstallprompt', event => {
        event.preventDefault();
        deferred = event;
        action.hidden = false;
        action.textContent = prompt.dataset.installLabel;
        help.textContent = prompt.dataset.defaultHelp;
        if (!dismissed) prompt.hidden = false;
    });
    action.addEventListener('click', async () => {
        if (!deferred) {
            help.textContent = /iPhone|iPad|iPod/i.test(navigator.userAgent) ? prompt.dataset.helpIos : prompt.dataset.helpGeneric;
            return;
        }
        await deferred.prompt();
        deferred = null;
        prompt.hidden = true;
    });
    window.addEventListener('appinstalled', () => { prompt.hidden = true; });
    if (/Android/i.test(navigator.userAgent) && !dismissed) {
        help.textContent = prompt.dataset.helpAndroid;
        action.textContent = prompt.dataset.helpAction;
        prompt.hidden = false;
    }
    if (/iPhone|iPad|iPod/i.test(navigator.userAgent) && !dismissed) {
        help.textContent = prompt.dataset.helpIos;
        action.textContent = prompt.dataset.helpAction;
        prompt.hidden = false;
    }
})();
