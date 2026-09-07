/// <reference lib="webworker" />
// The desk service worker (docs/OFFLINE.md §11, vite-plugin-pwa injectManifest). Route table, in registration order:
//   1. NetworkOnly guard list FIRST — any non-GET, /api/reception/sync*, /api/reception/blocks*, /api/ping, /broadcasting/*,
//      /api/device/broadcasting/*, /sanctum/*, /queue/*: mutations and liveness are never served from cache;
//   2. the reception shell (navigation to /panel/reception*) NetworkFirst 3 s → cold offline boot serves the last HTML
//      and the page hydrates from Dexie (the `bootstrap` in the props is treated as stale);
//   3. /api/reception/bootstrap* NetworkFirst 4 s (belt and braces — Dexie is the real store);
//   4. /api/reception/patients*, /history* NetworkFirst 4 s, 500 entries;
//   5. /api/reception/print-templates StaleWhileRevalidate;
//   6. /build/*, /fonts/* CacheFirst one year (hashed).
// Everything else (other panel routes, Inertia JSON with X-Inertia) is NetworkOnly: the desk is the only offline surface.
// No navigateFallback (an Inertia app must never get a generic index.html); no Background Sync (no iOS) — replay runs
// in-page (OFFLINE §7.3). The SKIP_WAITING handshake serves registerSW()'s prompt flow ("apply when the desk is idle").
import { clientsClaim } from 'workbox-core';
import { cleanupOutdatedCaches, precacheAndRoute } from 'workbox-precaching';
import { registerRoute } from 'workbox-routing';
import { CacheFirst, NetworkFirst, NetworkOnly, StaleWhileRevalidate } from 'workbox-strategies';
import { CacheableResponsePlugin } from 'workbox-cacheable-response';
import { ExpirationPlugin } from 'workbox-expiration';

declare let self: ServiceWorkerGlobalScope;

export const SHELL_CACHE = 'shell-v1';
export const STATIC_CACHE = 'static-v1';
export const API_BOOTSTRAP_CACHE = 'api-bootstrap';
export const API_PATIENTS_CACHE = 'api-patients';
export const PRINT_TEMPLATES_CACHE = 'print-templates';

/** Never cached: mutations and every liveness / realtime / auth path (OFFLINE §11). */
export const NETWORK_ONLY_PREFIXES = ['/api/reception/sync', '/api/reception/blocks', '/api/ping', '/broadcasting/', '/api/device/broadcasting/', '/sanctum/', '/queue/'] as const;

export function isNetworkOnly(method: string, pathname: string): boolean {
  return method !== 'GET' || NETWORK_ONLY_PREFIXES.some((p) => pathname.startsWith(p));
}

export function isReceptionShell(mode: RequestMode, pathname: string, headers: Headers): boolean {
  return mode === 'navigate' && pathname.startsWith('/panel/reception') && !headers.has('X-Inertia');
}

self.addEventListener('message', (event: ExtendableMessageEvent) => {
  const data = event.data as { type?: string } | null;
  if (data?.type === 'SKIP_WAITING') void self.skipWaiting();
});
clientsClaim();

precacheAndRoute(self.__WB_MANIFEST);
cleanupOutdatedCaches();

// 1. mutations and liveness — registered first so nothing below can shadow them
registerRoute(({ request, url }) => isNetworkOnly(request.method, url.pathname), new NetworkOnly());

// 2. the reception shell (Inertia HTML): last HTML on a cold offline boot, then Dexie hydrates
registerRoute(
  ({ request, url }) => isReceptionShell(request.mode, url.pathname, request.headers),
  new NetworkFirst({ cacheName: SHELL_CACHE, networkTimeoutSeconds: 3, plugins: [new CacheableResponsePlugin({ statuses: [200] })] }),
);

// 3. bootstrap document
registerRoute(
  ({ request, url }) => request.method === 'GET' && url.pathname.startsWith('/api/reception/bootstrap'),
  new NetworkFirst({ cacheName: API_BOOTSTRAP_CACHE, networkTimeoutSeconds: 4, plugins: [new CacheableResponsePlugin({ statuses: [200] }), new ExpirationPlugin({ maxEntries: 4, maxAgeSeconds: 259_200 })] }),
);

// 4. patients + history summaries
registerRoute(
  ({ request, url }) => request.method === 'GET' && (url.pathname.startsWith('/api/reception/patients') || url.pathname.startsWith('/api/reception/history')),
  new NetworkFirst({ cacheName: API_PATIENTS_CACHE, networkTimeoutSeconds: 4, plugins: [new CacheableResponsePlugin({ statuses: [200] }), new ExpirationPlugin({ maxEntries: 500, maxAgeSeconds: 2_592_000 })] }),
);

// 5. print templates (versioned server-side)
registerRoute(
  ({ request, url }) => request.method === 'GET' && (url.pathname.startsWith('/api/reception/print-templates') || url.pathname.startsWith('/panel/reception/print-templates')),
  new StaleWhileRevalidate({ cacheName: PRINT_TEMPLATES_CACHE, plugins: [new CacheableResponsePlugin({ statuses: [200] })] }),
);

// 6. hashed assets and fonts
registerRoute(
  ({ request, url }) => request.method === 'GET' && (url.pathname.startsWith('/build/') || url.pathname.startsWith('/fonts/') || url.pathname.startsWith('/icons/')),
  new CacheFirst({ cacheName: STATIC_CACHE, plugins: [new CacheableResponsePlugin({ statuses: [0, 200] }), new ExpirationPlugin({ maxAgeSeconds: 31_536_000, maxEntries: 300 })] }),
);
// Everything else: network only.
