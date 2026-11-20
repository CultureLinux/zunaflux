self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (_) { /* use generic text */ }
    const body = typeof data.body === 'string' ? data.body : 'Votre veille a été actualisée.';
    event.waitUntil(self.registration.showNotification('Zunaflux', {
        body,
        icon: '/images/icone-pwa-192.png',
        tag: typeof data.tag === 'string' ? data.tag : 'zunaflux',
        data: { url: '/articles' },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    // Only same-origin targets: the payload must never send the user elsewhere.
    let target = new URL('/articles', self.location.origin);
    try {
        const requested = new URL(event.notification.data?.url || '/articles', self.location.origin);
        if (requested.origin === self.location.origin) target = requested;
    } catch (_) { /* keep the default target */ }
    event.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
        const existing = windows.find((client) => new URL(client.url).origin === self.location.origin && 'focus' in client);
        if (!existing) return clients.openWindow(target.href);

        return existing.focus().then((client) => (new URL(client.url).pathname === target.pathname || !('navigate' in client) ? client : client.navigate(target.href)));
    }));
});
