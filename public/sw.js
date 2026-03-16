const CACHE_NAME = 'accord-v2';

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
        )
    );
    self.clients.claim();
});

// Only cache static build assets (JS/CSS bundles) — never intercept Inertia/HTML navigation
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // Only cache-first for versioned build assets (they have content hashes in the filename)
    if (
        event.request.method === 'GET' &&
        url.origin === self.location.origin &&
        url.pathname.startsWith('/build/assets/')
    ) {
        event.respondWith(
            caches.match(event.request).then((cached) => {
                if (cached) return cached;
                return fetch(event.request).then((response) => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
                    }
                    return response;
                });
            })
        );
    }
    // All other requests (HTML, API, chat routes) go straight to network — no SW interception
});

// ── Background push notifications ──────────────────────────────────────────
self.addEventListener('push', (event) => {
    if (!event.data) return;

    let payload = { title: 'AccordAI', body: 'New message', data: {} };
    try { payload = event.data.json(); } catch { /* use defaults */ }

    event.waitUntil(
        self.registration.showNotification(payload.title, {
            body: payload.body,
            icon: '/logo.png',
            badge: '/logo.png',
            tag: 'accord-message',
            renotify: true,
            data: payload.data,
        })
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = event.notification.data?.url ?? '/chats';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            for (const client of clientList) {
                if (client.url.startsWith(self.location.origin) && 'focus' in client) {
                    client.navigate(url);
                    return client.focus();
                }
            }
            return clients.openWindow(url);
        })
    );
});
