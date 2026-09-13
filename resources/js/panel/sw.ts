/// <reference lib="webworker" />
// The desk service worker (docs/OFFLINE.md §11, vite-plugin-pwa injectManifest). Route table, in registration order:
//   1. NAVIGATIONS (GET, mode 'navigate') — network, and the precached OFFLINE SHELL when the network cannot answer;
//   2. NetworkOnly guard list — any non-GET, /api/reception/sync*, /api/reception/blocks*, /api/ping, /broadcasting/*,
//      /api/device/broadcasting/*, /sanctum/*, /queue/*: mutations and liveness are never served from cache;
//   3. /api/reception/bootstrap* NetworkFirst 4 s (belt and braces — Dexie is the real store);
//   4. /api/reception/print-templates StaleWhileRevalidate;
//   5. /build/*, /fonts/*, /icons/* CacheFirst one year (hashed).
// Everything else (Inertia JSON with X-Inertia included) is NetworkOnly. No Background Sync (no iOS) — replay runs
// in-page (OFFLINE §7.3). The SKIP_WAITING handshake serves registerSW()'s prompt flow ("apply when the desk is idle").
//
// THE NAVIGATION STORY, AND WHY IT IS A SHELL AND NOT A CACHED PAGE.
//
// `shell-v1` used to hold the reception board's Inertia HTML (NetworkFirst 3 s) so that a cold offline boot of the
// installed PWA served the last page. That HTML is a RENDERED, AUTHENTICATED document: the whole board with patient
// names, fees and payment status, the viewer's `can` flags, their `doctor_scoped` answer and `auth.user` — all
// inlined in `data-page`. It was keyed by URL alone, and a service worker cannot tell who is asking: the session
// cookie is HttpOnly and unreadable here, the Cookie header is forbidden on a Request, and there is no client to ask
// on a cold boot. So on a slow or dead network it handed the previous user's board to the next one — a compounder
// included, and a compounder's whole boundary is which board they may hold. Nothing in the page can undo that
// either: a page booted from that cache believes it IS the previous user, because its props say so. THAT CACHE IS
// GONE AND STAYS GONE; `install` and `activate` below delete what it already holds on every installed tablet.
//
// Deleting it, on its own, also took away the desk's ability to OPEN offline, which is the product (BRIEF §5.F.1 —
// "clinic wifi in Bangladesh is unreliable"). The manifest's start_url is /panel/reception in `display: standalone`,
// so a failed navigation there is a chromeless window showing the browser's network-error page: no address bar, no
// back button, nothing to retry with, and the receptionist's unsynced event log still sitting in Dexie behind a door
// she cannot open. A tab reload and a tablet reboot fail the same way.
//
// So navigations now fall back to `public/offline.html` — a precached, hand-written, bilingual page that names
// nobody. It is safe to hand to whoever picks the tablet up because there is nothing in it to leak: no Inertia
// payload, no board, no `can` map, no IndexedDB read. It says the desk needs one connection, that saved work is
// safe and will upload itself, and offers Retry. Dexie is untouched throughout — the unsynced log survives every
// one of these paths, and the moment a real navigation succeeds the desk picks it up and replays it.
//
// Full cold-boot-INTO-THE-BOARD stays unbuilt on purpose: it needs the desk to prove offline WHO is standing at it,
// and the design's answer (OFFLINE §2.2: a PBKDF2 PIN in `meta.actorPin`, re-locking after 15 idle minutes) is
// declared in shared/offline/db.ts and written nowhere. Until that exists, an offline boot may not be served a
// document that names somebody.
//
// `api-patients` (removed with `shell-v1`) held /api/reception/patients* and /history* for 30 days, 500 entries —
// patient names, mobiles and visit history, again keyed by URL only, again returned to whoever asks once the network
// fails. It bought nothing: /api/reception/history is not a route at all, `patients/recent` is fetched once per
// bootstrap and written straight into Dexie (cachePatients), which is the store of record the desk actually reads,
// and `devicePatientLookup` has no caller. Dexie is one database per (tenant, device) and useDesk now refuses to
// read one whose actor is not the viewer; a second copy in the HTTP cache answers to no such check.
//
// /api/reception/bootstrap* stays: its document is exactly what Dexie already holds for this device, and what makes
// a cross-actor hit harmless is downstream — applyBootstrap writes the SERVER's actor into meta.actorUser and
// useDesk compares it with the authenticated viewer before rendering a single row out of that cache.
import { clientsClaim } from 'workbox-core';
import { cleanupOutdatedCaches, matchPrecache, precacheAndRoute } from 'workbox-precaching';
import { registerRoute } from 'workbox-routing';
import { CacheFirst, NetworkFirst, NetworkOnly, StaleWhileRevalidate } from 'workbox-strategies';
import { CacheableResponsePlugin } from 'workbox-cacheable-response';
import { ExpirationPlugin } from 'workbox-expiration';

declare let self: ServiceWorkerGlobalScope;

/** Deleted on sight — the two leaky caches of the pre-shell worker. Also purged from the page (panel/pwa.ts). */
export const LEGACY_CACHES = ['shell-v1', 'api-patients'] as const;

export const STATIC_CACHE = 'static-v1';
export const API_BOOTSTRAP_CACHE = 'api-bootstrap';
export const PRINT_TEMPLATES_CACHE = 'print-templates';

/**
 * The data-free page every unreachable navigation lands on (public/offline.html, precached by the glob in
 * vite.config.ts). Kept at the site root rather than under /panel/ so it is a plain static file the web server
 * serves without touching Laravel's route table — the service worker only ever reads it out of the precache.
 */
export const OFFLINE_SHELL_URL = '/offline.html';

/**
 * Never cached: mutations and every liveness / realtime / auth path (OFFLINE §11), plus `/panel/reception/visits/*`
 * — the compounder's vitals entry (BRIEF §5.G.2) is a clinical body and OFFLINE §6.2 keeps those off the device
 * entirely, so an offline desk gets the shell instead of yesterday's blood pressure. It is named here rather than
 * left to the fallthrough so that no future route can decide to cache it.
 */
export const NETWORK_ONLY_PREFIXES = ['/api/reception/sync', '/api/reception/blocks', '/api/ping', '/broadcasting/', '/api/device/broadcasting/', '/sanctum/', '/queue/', '/panel/reception/visits/'] as const;

/** The printable templates (§10) — versioned server-side, and the one document the desk may render from a cache. */
export const PRINT_TEMPLATE_PREFIXES = ['/api/reception/print-templates', '/panel/reception/print-templates'] as const;

export function isNetworkOnly(method: string, pathname: string): boolean {
  return method !== 'GET' || NETWORK_ONLY_PREFIXES.some((p) => pathname.startsWith(p));
}

/**
 * Which requests get the offline shell when the network fails.
 *
 * GET only: a navigation that is a form POST must keep failing as a POST — answering one with a 200 HTML body
 * would tell the browser the submission went somewhere. Print templates are excluded because they are a real
 * offline capability (§10, StaleWhileRevalidate below) opened in a print frame, and a frame load is a navigation
 * too: shadowing that route would replace a token slip with an apology. Everything else in scope is fair game,
 * `/panel/reception/visits/*` included — the shell carries no clinical body, so §6.2 has nothing to object to and
 * a compounder gets an explanation instead of a chromeless error page.
 */
export function isShellNavigation(method: string, mode: string, pathname: string): boolean {
  return method === 'GET' && mode === 'navigate' && !PRINT_TEMPLATE_PREFIXES.some((p) => pathname.startsWith(p));
}

self.addEventListener('message', (event: ExtendableMessageEvent) => {
  const data = event.data as { type?: string } | null;
  if (data?.type === 'SKIP_WAITING') void self.skipWaiting();
});

// Emptying the leaky caches is done TWICE, and the first one is the one that matters on a kiosked tablet.
// `install` runs as soon as this worker is downloaded, while the OLD worker is still in control — so the previous
// user's board is destroyed the moment the tablet notices there is an update, hours before anyone applies it.
// `activate` repeats it because the old worker keeps writing to `shell-v1` until it is actually replaced, and
// because cleanupOutdatedCaches() only knows about workbox's own precaches, never a hand-named one.
// Deleting an HTTP response destroys no work — the desk's unsynced events live in Dexie (`db.events`), untouched.
const purgeLegacyCaches = (): Promise<unknown> => Promise.all(LEGACY_CACHES.map((name) => caches.delete(name)));

self.addEventListener('install', (event: ExtendableEvent) => { event.waitUntil(purgeLegacyCaches()); });
self.addEventListener('activate', (event: ExtendableEvent) => { event.waitUntil(purgeLegacyCaches()); });
clientsClaim();

precacheAndRoute(self.__WB_MANIFEST);
cleanupOutdatedCaches();

// 1. navigations — FIRST, so that every in-scope page load has somewhere to land. The network is tried with a bare
//    fetch (no strategy, no cache write: nothing about a /panel/ document may be stored), and only a thrown fetch —
//    a real transport failure — falls back. A 4xx/5xx is a server that ANSWERED and must be shown as itself, or an
//    expired session would look like a dead wifi and a receptionist would wait for a line that is already up.
registerRoute(
  ({ request, url }) => isShellNavigation(request.method, request.mode, url.pathname),
  async ({ request }) => {
    try {
      return await fetch(request);
    } catch {
      return (await matchPrecache(OFFLINE_SHELL_URL)) ?? Response.error();
    }
  },
);

// 2. mutations and liveness — registered before every caching route so nothing below can shadow them
registerRoute(({ request, url }) => isNetworkOnly(request.method, url.pathname), new NetworkOnly());

// 3. bootstrap document
registerRoute(
  ({ request, url }) => request.method === 'GET' && url.pathname.startsWith('/api/reception/bootstrap'),
  new NetworkFirst({ cacheName: API_BOOTSTRAP_CACHE, networkTimeoutSeconds: 4, plugins: [new CacheableResponsePlugin({ statuses: [200] }), new ExpirationPlugin({ maxEntries: 4, maxAgeSeconds: 259_200 })] }),
);

// (no patients/history route: Dexie is the store of record for those rows — see the header)

// 4. print templates (versioned server-side)
registerRoute(
  ({ request, url }) => request.method === 'GET' && PRINT_TEMPLATE_PREFIXES.some((p) => url.pathname.startsWith(p)),
  new StaleWhileRevalidate({ cacheName: PRINT_TEMPLATES_CACHE, plugins: [new CacheableResponsePlugin({ statuses: [200] })] }),
);

// 5. hashed assets and fonts
registerRoute(
  ({ request, url }) => request.method === 'GET' && (url.pathname.startsWith('/build/') || url.pathname.startsWith('/fonts/') || url.pathname.startsWith('/icons/')),
  new CacheFirst({ cacheName: STATIC_CACHE, plugins: [new CacheableResponsePlugin({ statuses: [0, 200] }), new ExpirationPlugin({ maxAgeSeconds: 31_536_000, maxEntries: 300 })] }),
);
// Everything else: network only.
