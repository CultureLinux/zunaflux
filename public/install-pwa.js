(() => {
    const prompt = document.querySelector('[data-pwa-install]');
    if (!prompt || !/Android|iPhone|iPad|iPod/i.test(navigator.userAgent) || window.matchMedia('(display-mode: standalone)').matches || navigator.standalone) return;
    const action = prompt.querySelector('[data-pwa-install-action]');
    const dismiss = prompt.querySelector('[data-pwa-install-dismiss]');
    let deferred;
    let dismissed = false;
    try { dismissed = sessionStorage.getItem('zunaflux-install-dismissed') === '1'; } catch (_) {}
    dismiss.addEventListener('click', () => {
        prompt.hidden = true;
        dismissed = true;
        try { sessionStorage.setItem('zunaflux-install-dismissed', '1'); } catch (_) {}
    });
    window.addEventListener('beforeinstallprompt', event => {
        event.preventDefault();
        deferred = event;
        action.hidden = false;
        prompt.querySelector('[data-pwa-install-help]').textContent = 'Retrouvez votre lecteur parmi vos applications.';
        if (!dismissed) prompt.hidden = false;
    });
    action.addEventListener('click', async () => {
        if (!deferred) return;
        await deferred.prompt();
        deferred = null;
        prompt.hidden = true;
    });
    window.addEventListener('appinstalled', () => { prompt.hidden = true; });
    if (/Android/i.test(navigator.userAgent) && !dismissed) {
        prompt.querySelector('[data-pwa-install-help]').textContent = 'Ouvrez le menu de votre navigateur puis choisissez « Installer l’application » ou « Ajouter à l’écran d’accueil ».';
        action.hidden = true;
        prompt.hidden = false;
    }
    if (/iPhone|iPad|iPod/i.test(navigator.userAgent) && !dismissed) {
        prompt.querySelector('[data-pwa-install-help]').textContent = 'Dans Safari, touchez Partager puis « Sur l’écran d’accueil ».';
        action.hidden = true;
        prompt.hidden = false;
    }
})();
