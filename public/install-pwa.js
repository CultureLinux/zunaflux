(() => {
    const prompt = document.querySelector('[data-pwa-install]');
    if (!prompt || !/Android|iPhone|iPad|iPod/i.test(navigator.userAgent) || window.matchMedia('(display-mode: standalone)').matches || navigator.standalone) return;
    const action = prompt.querySelector('[data-pwa-install-action]');
    const dismiss = prompt.querySelector('[data-pwa-install-dismiss]');
    const help = prompt.querySelector('[data-pwa-install-help]');
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
        action.textContent = 'Installer';
        help.textContent = 'Retrouvez votre lecteur parmi vos applications.';
        if (!dismissed) prompt.hidden = false;
    });
    action.addEventListener('click', async () => {
        if (!deferred) {
            help.textContent = /iPhone|iPad|iPod/i.test(navigator.userAgent)
                ? 'Dans Safari, touchez Partager puis « Sur l’écran d’accueil ».'
                : 'Ouvrez le menu de votre navigateur puis choisissez « Installer l’application » ou « Ajouter à l’écran d’accueil ».';
            return;
        }
        await deferred.prompt();
        deferred = null;
        prompt.hidden = true;
    });
    window.addEventListener('appinstalled', () => { prompt.hidden = true; });
    if (/Android/i.test(navigator.userAgent) && !dismissed) {
        help.textContent = 'Votre navigateur permet d’ajouter Zunaflux à votre écran d’accueil.';
        action.textContent = 'Comment installer';
        prompt.hidden = false;
    }
    if (/iPhone|iPad|iPod/i.test(navigator.userAgent) && !dismissed) {
        help.textContent = 'Ajoutez Zunaflux à votre écran d’accueil depuis Safari.';
        action.textContent = 'Comment installer';
        prompt.hidden = false;
    }
})();
