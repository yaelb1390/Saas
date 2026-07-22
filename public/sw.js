/**
 * Service worker de BM Business OS.
 *
 * Criterio para un sistema de venta: los DATOS nunca se sirven de caché (un precio o un stock
 * viejo es peor que un error). Solo se cachean los archivos estáticos —el JS/CSS compilado y los
 * iconos— para que la app abra rápido y consuma menos datos. Todo lo demás (páginas y API) va
 * siempre a la red.
 *
 * Sube la versión para invalidar la caché tras un despliegue.
 */
const VERSION = 'v1';
const STATIC_CACHE = `bmos-static-${VERSION}`;

// Solo se cachea lo que es inmutable o cambia con el build (Vite pone hash en el nombre).
function esEstatico(url) {
    return url.pathname.startsWith('/build/')
        || url.pathname.startsWith('/images/')
        || url.pathname === '/favicon.ico'
        || url.pathname === '/manifest.json';
}

self.addEventListener('install', (event) => {
    // Activa la versión nueva sin esperar a que se cierren las pestañas viejas.
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        // Borra cachés de versiones anteriores.
        const nombres = await caches.keys();
        await Promise.all(nombres.filter((n) => n !== STATIC_CACHE).map((n) => caches.delete(n)));
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', (event) => {
    const req = event.request;

    // Solo GET y solo mismo origen; lo demás pasa directo a la red.
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    if (esEstatico(url)) {
        // Estáticos: primero caché, y si no está, red (y se guarda).
        event.respondWith((async () => {
            const cache = await caches.open(STATIC_CACHE);
            const enCache = await cache.match(req);
            if (enCache) return enCache;
            const res = await fetch(req);
            if (res.ok) cache.put(req, res.clone());
            return res;
        })());
        return;
    }

    // Páginas y API: siempre a la red. Sin datos rancios en una caja registradora.
});
