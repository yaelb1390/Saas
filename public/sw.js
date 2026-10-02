/**
 * Service worker de BM Business OS.
 *
 * Criterio general, que NO cambia: los datos no se sirven de caché. Un precio o un stock viejo en
 * un informe es peor que un error, porque el error se ve y el dato viejo no. Todo el panel sigue
 * yendo siempre a la red.
 *
 * La excepción son LAS DOS PANTALLAS DE COBRO, y está acotada a ellas a propósito. En un colmado la
 * fibra se cae, y hasta ahora eso significaba que no se podía cobrar: la aplicación ni siquiera
 * abría. Ahí el criterio se invierte, porque las alternativas no son «dato fresco o dato viejo» sino
 * «cobrar con el precio de hace un rato o no cobrar». Y el terminal enseña de cuándo son los precios
 * que tiene, para que el cajero lo sepa.
 *
 * Sube la versión para invalidar la caché tras un despliegue.
 */
// v3: se añade la reserva de las pantallas del POS para poder vender sin conexión. Subir la versión
// es obligatorio o los navegadores que ya tienen cacheada la v2 nunca la verían.
// v4: página de resguardo propia cuando una pantalla no abre por falta de red.
// v5: iconos nuevos en los manifiestos; sin esto la caché seguiría sirviendo el manifiesto viejo.
const VERSION = 'v5';
const STATIC_CACHE = `bmos-static-${VERSION}`;
const POS_CACHE = `bmos-pos-${VERSION}`;

/**
 * Lo que se enseña cuando una pantalla no se puede abrir sin red, en vez del dinosaurio del
 * navegador. No lleva datos —el criterio de arriba sigue en pie—: solo avisa, deja reintentar y
 * ofrece ir a cobrar, que es lo único que sí funciona sin conexión.
 */
const OFFLINE_URL = '/offline.html';

// Todas las cachés que esta versión usa. Las que no estén aquí se borran al activar.
const CACHES_VIVAS = [STATIC_CACHE, POS_CACHE];

// Solo se cachea lo que es inmutable o cambia con el build (Vite pone hash en el nombre).
function esEstatico(url) {
    return url.pathname.startsWith('/build/')
        || url.pathname.startsWith('/images/')
        || url.pathname === '/favicon.ico'
        || url.pathname === '/manifest.json'
        || url.pathname === '/manifest-pos.json';
}

/**
 * Las pantallas desde las que se cobra, y nada más.
 *
 * Comparación exacta y no `startsWith`: `/panel/pos/cobrar` y `/panel/pos/sincronizar` cuelgan del
 * mismo prefijo y JAMÁS deben servirse de una copia guardada. Devolver de caché la respuesta de un
 * cobro sería darle al cajero un «venta registrada» que nunca ocurrió.
 */
function esPantallaDeCobro(url) {
    return url.pathname === '/panel/pos' || url.pathname === '/panel/pos-rapido';
}

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        // Se guarda AL INSTALAR y no la primera vez que hace falta: el día que hace falta es
        // justamente el día que no hay red para ir a buscarla.
        try {
            const cache = await caches.open(STATIC_CACHE);
            await cache.add(new Request(OFFLINE_URL, { cache: 'reload' }));
        } catch {
            // Sin la página de resguardo el SW sigue sirviendo: no puede impedir que se instale la
            // versión que mantiene el cobro sin conexión.
        }
    })());

    // Activa la versión nueva sin esperar a que se cierren las pestañas viejas.
    self.skipWaiting();
});

/** La página de resguardo guardada, o un error de red si por algo no llegó a guardarse. */
async function paginaSinConexion() {
    const cache = await caches.open(STATIC_CACHE);
    return (await cache.match(OFFLINE_URL)) ?? Response.error();
}

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        // Borra cachés de versiones anteriores.
        const nombres = await caches.keys();
        await Promise.all(nombres.filter((n) => !CACHES_VIVAS.includes(n)).map((n) => caches.delete(n)));
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

    if (esPantallaDeCobro(url)) {
        /*
         * Red primero, y la última copia buena solo si la red falla.
         *
         * En este orden y no al revés: con conexión el cajero ve el estado real de la caja y del
         * catálogo, como siempre. La copia es el paracaídas, no el camino normal.
         *
         * Se guarda una copia por RUTA y no por petición completa, ignorando la query: `?mesa=3` y
         * `?mesa=4` son la misma pantalla, y guardar una entrada por combinación llenaría la caché
         * de copias equivalentes hasta que el navegador empezara a tirarlas —quizá justo la única
         * que hacía falta—.
         */
        event.respondWith((async () => {
            const cache = await caches.open(POS_CACHE);

            try {
                const res = await fetch(req);

                // Solo se guarda una respuesta buena. Un 302 al login o un 500 sustituirían la copia
                // útil por una inservible, y el día de la caída el terminal abriría en la pantalla
                // de sesión caducada.
                if (res.ok && res.type === 'basic') {
                    cache.put(url.pathname, res.clone());
                }

                return res;
            } catch {
                const enCache = await cache.match(url.pathname);
                if (enCache) return enCache;

                // El terminal nunca se abrió con red en este equipo: no hay copia que dar.
                return paginaSinConexion();
            }
        })());
        return;
    }

    if (req.mode === 'navigate') {
        // Abrir una pantalla: siempre a la red. Solo si la red FALLA se enseña la página de
        // resguardo, que no tiene datos. Un 500 o un 302 del servidor pasan tal cual.
        event.respondWith(fetch(req).catch(() => paginaSinConexion()));
        return;
    }

    // Todo lo demás —peticiones de datos—: siempre a la red. Sin datos rancios fuera del mostrador.
});
