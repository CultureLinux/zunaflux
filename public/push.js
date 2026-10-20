(() => {
    const root = document.querySelector('[data-push-notifications]');
    if (!root) return;
    const enable = root.querySelector('[data-push-enable]');
    const disable = root.querySelector('[data-push-disable]');
    const test = root.querySelector('[data-push-test]');
    const status = root.querySelector('[role="status"]');
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !window.isSecureContext || !root.dataset.publicKey) {
        status.textContent = 'Les notifications ne sont pas disponibles sur cet appareil ou ce serveur.';
        return;
    }
    const key = root.dataset.publicKey;
    const padded = key + '='.repeat((4 - key.length % 4) % 4);
    const bytes = Uint8Array.from(atob(padded.replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));
    const send = async (method, data, url = root.dataset.url) => {
        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': root.dataset.csrf },
            body: JSON.stringify(data),
        });
        if (!response.ok) throw new Error(`server-${response.status}`);
        return response.json();
    };
    const withTimeout = (promise, message) => new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error(message)), 20000);
        Promise.resolve(promise).then(value => { clearTimeout(timer); resolve(value); }, error => { clearTimeout(timer); reject(error); });
    });
    navigator.serviceWorker.register('/sw.js').then(async () => {
        const registration = await withTimeout(navigator.serviceWorker.ready, 'worker-timeout');
        let currentSubscription = null;
        const refresh = async () => {
            const subscription = currentSubscription = await registration.pushManager.getSubscription();
            if (!subscription) {
                enable.disabled = false;
                disable.disabled = true;
                if (test) test.disabled = true;
                status.textContent = 'Notifications désactivées sur cet appareil. L’autorisation du navigateur ne suffit pas : activez-les ici.';
                return;
            }
            const result = await send('POST', { endpoint: subscription.endpoint }, root.dataset.statusUrl);
            enable.disabled = result.subscribed;
            disable.disabled = !result.subscribed;
            if (test) test.disabled = !result.subscribed;
            status.textContent = result.subscribed ? 'Notifications activées pour ce compte sur cet appareil.' : 'Cet appareil possède un abonnement, mais pas pour ce compte. Activez les notifications ici.';
        };
        enable.addEventListener('click', async () => {
            enable.disabled = true;
            try {
                status.textContent = 'Connexion au service de notifications…';
                const pending = currentSubscription || registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: bytes });
                const subscription = await withTimeout(pending, 'push-timeout');
                currentSubscription = subscription;
                status.textContent = 'Enregistrement de cet appareil…';
                await withTimeout(send('POST', subscription.toJSON()), 'server-timeout');
                disable.disabled = false;
                if (test) test.disabled = false;
                status.textContent = 'Notifications activées pour ce compte sur cet appareil.';
            } catch (error) {
                enable.disabled = false;
                if (error.name === 'NotAllowedError') status.textContent = 'Firefox a refusé la demande. Vérifiez ses autorisations de notifications.';
                else if (error.message === 'push-timeout') status.textContent = 'Le service de notifications ne répond pas. Rechargez la page et réessayez.';
                else if (error.message === 'server-timeout') status.textContent = 'Le serveur ne répond pas pour enregistrer cet appareil. Réessayez.';
                else if (error.message?.startsWith('server-')) status.textContent = 'Le serveur n’a pas enregistré cet appareil. Vérifiez la session et la configuration des notifications.';
                else {
                    const knownErrors = ['AbortError', 'InvalidAccessError', 'InvalidStateError', 'NetworkError', 'NotSupportedError', 'SecurityError', 'TypeError'];
                    const detail = knownErrors.includes(error.name) ? ` (${error.name})` : '';
                    status.textContent = `Le navigateur n’a pas pu créer l’abonnement aux notifications${detail}. Vérifiez sa connexion et les autorisations du site.`;
                }
            }
        });
        disable.addEventListener('click', async () => {
            disable.disabled = true;
            try {
                const subscription = await registration.pushManager.getSubscription();
                if (subscription) { await send('DELETE', { endpoint: subscription.endpoint }); await subscription.unsubscribe(); }
                currentSubscription = null;
                enable.disabled = false;
                if (test) test.disabled = true;
                status.textContent = 'Notifications désactivées sur cet appareil.';
            } catch (_) { status.textContent = 'Impossible de désactiver les notifications.'; disable.disabled = false; }
        });
        if (test) test.addEventListener('click', async () => {
            test.disabled = true;
            try {
                const subscription = await registration.pushManager.getSubscription();
                if (!subscription) throw new Error('no subscription');
                await send('POST', { endpoint: subscription.endpoint }, test.dataset.url);
                status.textContent = 'Notification de test envoyée. Vérifiez les notifications de cet appareil.';
            } catch (_) { status.textContent = 'Échec du test. Vérifiez l’activation et la configuration des notifications.'; }
            finally { test.disabled = false; }
        });
        await refresh();
    }).catch(() => { status.textContent = 'Impossible de préparer les notifications sur cet appareil.'; });
})();
