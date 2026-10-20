self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (_) { /* use generic text */ }
    const body = typeof data.body === 'string' ? data.body : 'Votre veille a été actualisée.';
    event.waitUntil(self.registration.showNotification('Zunaflux', {
        body,
        icon: '/images/icone-rss-livre.png',
        tag: typeof data.tag === 'string' ? data.tag : 'zunaflux',
        data: { url: '/articles' },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(clients.openWindow(new URL('/articles', self.location.origin).href));
});
