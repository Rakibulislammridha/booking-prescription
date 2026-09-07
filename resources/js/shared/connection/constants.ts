// docs/OFFLINE.md §3.2 — foundation-owned; modules never tune these.
export const HEARTBEAT_TIMEOUT_MS = 5_000;
export const HEARTBEAT_INTERVAL_MS = { online: 30_000, degraded: 15_000, offline: 5_000 } as const;
export const HEARTBEAT_JITTER_MS = 1_000;
export const HEARTBEAT_FAILS_TO_OFFLINE = 2;   // consecutive
export const WS_DOWN_DEBOUNCE_MS = 10_000;     // online → degraded only after WS down this long
export const WS_UP_DEBOUNCE_MS = 2_000;        // degraded → online only after WS up this long
export const HEARTBEAT_HIDDEN_MULTIPLIER = 4;  // hidden tab: interval × 4, never disabled
export const HEARTBEAT_PATH = '/api/ping';     // routes/api/tenancy.php (api.tenancy.ping), no auth, Cache-Control: no-store
