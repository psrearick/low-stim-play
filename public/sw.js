const CACHE_NAME = 'static-v1';

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

// Only the fingerprinted Vite build output (/build/*) is cached here --
// unlike legacy's static HTML pages, every page in this app is rendered
// server-side per request (a fresh CSRF token and Livewire wire:snapshot
// each time), so caching HTML for offline use would risk serving a stale
// token or a snapshot that can't rehydrate. Vite's content-hashed
// filenames make cache-first safe for this subset: a new deploy always
// produces new filenames, so a cached asset is never stale by definition.
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    if (event.request.method !== 'GET' || !url.pathname.startsWith('/build/')) {
        return;
    }

    event.respondWith(
        caches.open(CACHE_NAME).then((cache) => cache.match(event.request).then((cached) => {
            if (cached) {
                return cached;
            }

            return fetch(event.request).then((response) => {
                cache.put(event.request, response.clone());

                return response;
            });
        })),
    );
});
