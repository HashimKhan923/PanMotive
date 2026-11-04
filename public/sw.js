/* Panmotive service worker — hand-rolled (see Phase 8 notes).
   - App shell / navigations: network-first with an offline fallback.
   - Product images (/storage/... , /images/...): cache-first, capped + expiring.
   - Built assets (/build/...): stale-while-revalidate. */

const VERSION = 'pm-v3';
const SHELL_CACHE = `${VERSION}-shell`;
const IMAGE_CACHE = `${VERSION}-images`;
const ASSET_CACHE = `${VERSION}-assets`;
const OFFLINE_URL = '/offline.html';
const IMAGE_MAX_ENTRIES = 200;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL_CACHE).then((cache) => cache.addAll([OFFLINE_URL])).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

async function trimCache(cacheName, maxEntries) {
    const cache = await caches.open(cacheName);
    const keys = await cache.keys();
    if (keys.length > maxEntries) {
        await cache.delete(keys[0]);
        return trimCache(cacheName, maxEntries);
    }
}

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') return;

    const url = new URL(request);
    if (url.origin !== self.location.origin) return;

    // Navigations — network first, fall back to the offline page.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_URL))
        );
        return;
    }

    // Product images — cache first.
    if (url.pathname.startsWith('/storage/') || url.pathname.startsWith('/images/')) {
        event.respondWith(
            caches.open(IMAGE_CACHE).then(async (cache) => {
                const hit = await cache.match(request);
                if (hit) return hit;
                const res = await fetch(request);
                if (res.ok) {
                    cache.put(request, res.clone());
                    trimCache(IMAGE_CACHE, IMAGE_MAX_ENTRIES);
                }
                return res;
            })
        );
        return;
    }

    // Built assets — stale while revalidate.
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(
            caches.open(ASSET_CACHE).then(async (cache) => {
                const hit = await cache.match(request);
                const fetching = fetch(request).then((res) => {
                    if (res.ok) cache.put(request, res.clone());
                    return res;
                }).catch(() => hit);
                return hit || fetching;
            })
        );
    }
});

// Web push — payload shape matches NotificationChannels\WebPush\WebPushMessage.
self.addEventListener('push', (event) => {
    if (!event.data) return;
    let data = {};
    try { data = event.data.json(); } catch (e) { data = { title: 'Panmotive', body: event.data.text() }; }

    event.waitUntil(
        self.registration.showNotification(data.title || 'Panmotive', {
            body: data.body || '',
            icon: data.icon || '/pwa-192x192.png',
            badge: '/pwa-192x192.png',
            data: { url: data.url || (data.data && data.data.url) || '/' },
        })
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = (event.notification.data && event.notification.data.url) || '/';
    event.waitUntil(clients.openWindow(target));
});
