/*
 * Service worker mínimo y sin riesgo: cachea SOLO estáticos inmutables
 * (los assets de Vite llevan hash en el nombre) y las imágenes del club.
 * Las páginas y la API van siempre a la red — nunca datos viejos.
 */
const CACHE = 'nc-static-v2';

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    if (event.request.method !== 'GET' || url.origin !== location.origin) {
        return;
    }

    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/img/')) {
        event.respondWith(
            caches.open(CACHE).then(async (cache) => {
                const hit = await cache.match(event.request);
                if (hit) {
                    return hit;
                }
                const response = await fetch(event.request);
                if (response.ok) {
                    cache.put(event.request, response.clone());
                }
                return response;
            })
        );
    }
});
