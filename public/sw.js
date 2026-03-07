const CACHE_NAME = 'accord-v1';
const STATIC_ASSETS = ['/'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS))
    );
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

// Network-first strategy: always try network, fall back to cache
self.addEventListener('fetch', (event) => {
    // Skip non-GET, cross-origin, and API requests
    if (
        event.request.method !== 'GET' ||
        !event.request.url.startsWith(self.location.origin) ||
        event.request.url.includes('/api/') ||
        event.request.url.includes('/chats/') && event.request.url.includes('/messages')
    ) {
        return;
    }

    event.respondWith(
        fetch(event.request).catch(() => caches.match(event.request))
    );
});
