// The heartbeat timer (docs/OFFLINE.md §3.2): GET /api/ping every HEARTBEAT_INTERVAL_MS[mode] ± jitter
// (× 4 while the tab is hidden, never disabled), 5 s AbortController timeout. Extra beats fire immediately on
// `window online`, on the tab becoming visible, on any axios network error, and when the socket reports
// `connected` (to confirm HTTP too). Plain fetch on purpose: it must not go through the axios interceptor.
import { useConnection } from './store';
import { HEARTBEAT_HIDDEN_MULTIPLIER, HEARTBEAT_INTERVAL_MS, HEARTBEAT_JITTER_MS, HEARTBEAT_PATH, HEARTBEAT_TIMEOUT_MS } from './constants';

type Timer = ReturnType<typeof setTimeout> | undefined;

let baseUrl = '';
let running = false;
let timer: Timer;
let inFlight: Promise<boolean> | null = null;
let unsubscribers: Array<() => void> = [];

/** One GET /api/ping with a hard timeout. `true` only for a 2xx (a 502 from the proxy is not "reachable" for the desk). */
export async function ping(base: string = baseUrl, timeoutMs: number = HEARTBEAT_TIMEOUT_MS): Promise<boolean> {
  const controller = new AbortController();
  const abort = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const res = await fetch(`${base}${HEARTBEAT_PATH}`, {
      method: 'GET',
      cache: 'no-store',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      signal: controller.signal,
    });
    // Drain (or cancel) the tiny body so the connection is released back to the pool; only res.ok matters.
    await drainBody(res);
    return res.ok;
  } catch {
    return false;
  } finally {
    clearTimeout(abort);
  }
}

async function drainBody(res: Response): Promise<void> {
  try {
    if (res.body && typeof res.body.cancel === 'function') await res.body.cancel();
    else if (typeof res.text === 'function') await res.text();
  } catch {
    // a body that cannot be read/cancelled is not a reachability signal
  }
}

export function jitter(ms: number = HEARTBEAT_JITTER_MS): number {
  return Math.round((Math.random() * 2 - 1) * ms);
}

export function nextHeartbeatDelay(): number {
  const { mode, tabVisible } = useConnection.getState();
  const base = HEARTBEAT_INTERVAL_MS[mode] * (tabVisible ? 1 : HEARTBEAT_HIDDEN_MULTIPLIER);
  return Math.max(1_000, base + jitter());
}

function schedule(): void {
  clearTimeout(timer);
  if (!running) return;
  timer = setTimeout(() => { void beat(); }, nextHeartbeatDelay());
}

/** Run one heartbeat now (coalesced with any in-flight one) and reschedule the next from the resulting mode. */
async function beat(): Promise<boolean> {
  if (!running) return false;
  if (inFlight) return inFlight;
  inFlight = (async () => {
    const ok = await ping();
    if (running) useConnection.getState().heartbeatResult(ok);
    return ok;
  })();
  try {
    return await inFlight;
  } finally {
    inFlight = null;
    schedule();
  }
}

/** Immediate heartbeat; a no-op (resolves false) before startHeartbeat(). Called by the axios interceptor and the bridge. */
export function triggerHeartbeat(): Promise<boolean> {
  if (!running) return Promise.resolve(false);
  return beat();
}

export function isHeartbeatRunning(): boolean {
  return running;
}

/** Start the timer (idempotent). Returns a stop function. */
export function startHeartbeat(base = ''): () => void {
  if (running) return stopHeartbeat;
  baseUrl = base;
  running = true;
  const store = useConnection;
  unsubscribers = [
    store.subscribe((s) => s.mode, () => schedule()),
    store.subscribe((s) => s.tabVisible, (visible) => { if (visible) void beat(); else schedule(); }),
    store.subscribe((s) => s.wsState, (ws) => { if (ws === 'connected') void beat(); }),
    store.subscribe((s) => s.browserOnline, (online) => { if (online) void beat(); }),
  ];
  void beat();
  return stopHeartbeat;
}

export function stopHeartbeat(): void {
  running = false;
  clearTimeout(timer);
  timer = undefined;
  for (const off of unsubscribers) off();
  unsubscribers = [];
}
