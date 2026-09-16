self.addEventListener('push', (event) => {
    let payload = {};

    try {
        payload = event.data?.json() || {};
    } catch {
        payload = { body: event.data?.text() || '' };
    }

    const title = payload.title || 'Push test';
    const options = {
        body: payload.body,
        icon: payload.icon || '/apple-touch-icon.png',
        badge: payload.badge || '/apple-touch-icon.png',
        tag: payload.tag,
        data: payload.data || {},
        actions: payload.actions || [],
        requireInteraction: payload.requireInteraction || false,
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const targetUrl = new URL(event.notification.data?.url || '/dashboard', self.location.origin).href;

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const existingWindow = windows.find((client) => client.url.startsWith(self.location.origin));

        if (existingWindow) {
            await existingWindow.navigate(targetUrl);
            return existingWindow.focus();
        }

        return self.clients.openWindow(targetUrl);
    })());
});
