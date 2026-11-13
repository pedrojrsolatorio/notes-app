// Service worker for the installed notes app.
//
// The sync API and attachment downloads are never cached. The app shell and
// its hashed assets are served cache-first so the installed app opens offline.

const CACHE_NAME = 'notes-offline-v1';
const PRECACHE = [
    '/app',
    '/manifest.webmanifest',
    '/favicon.ico',
    '/favicon.svg',
    '/apple-touch-icon.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE)),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((names) => Promise.all(
            names
                .filter((name) => name !== CACHE_NAME)
                .map((name) => caches.delete(name)),
        )).then(() => self.clients.claim()),
    );
});

self.addEventListener('message', (event) => {
    if (event.data === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    // Sync and attachment downloads are always network traffic.
    if (url.pathname.startsWith('/sync') || url.pathname.startsWith('/note-attachments/')) {
        return;
    }

    // Navigations are network first. The shell HTML carries signed-in state,
    // so it must never be served cache first; the cache only covers offline.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).then((response) => {
                if (response.ok) {
                    const copy = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put('/app', copy));
                }

                return response;
            }).catch(() => caches.match('/app').then((cached) => cached ?? new Response('Offline', {
                status: 503,
                headers: { 'Content-Type': 'text/plain' },
            }))),
        );

        return;
    }

    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/manifest') || url.pathname.startsWith('/favicon') || url.pathname.startsWith('/apple-touch-icon')) {
        event.respondWith(
            caches.match(request).then((cached) => {
                if (cached) {
                    return cached;
                }

                return fetch(request).then((response) => {
                    if (response.ok) {
                        const copy = response.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
                    }

                    return response;
                });
            }),
        );
    }
});
