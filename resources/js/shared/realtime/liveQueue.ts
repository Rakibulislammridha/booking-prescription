// resources/js/shared/realtime/liveQueue.ts — WebSocket first, polling fallback, version dedupe (docs/REALTIME.md §6).
// Reads useConnection (OFFLINE.md §3) and never decides connectivity itself. subscribeQueue() is the primitive;
// React code uses useQueueState(). Delegated subtree: engineer Q owns shared/realtime/** from here on.
import { useConnection } from '../connection/store';
import { bootConnection } from '../connection/boot';
import { triggerHeartbeat } from '../connection/heartbeat';
import { loadEcho, type ReverbEcho } from './echo';
import { QUEUE_EVENTS, tenantChannel, type QueueState } from './types';

export const POLL_INTERVAL_MS = 5_000;
export const POLL_HIDDEN_INTERVAL_MS = 30_000;
export const WS_SILENCE_GUARD_MS = 90_000;     // connected but silent while running → one sanity poll

export interface LiveQueueHandle {
  getState(): QueueState | null;
  stop(): void;
}

export interface SubscribeQueueOptions {
  tenantId: string;
  doctorSlug: string;
  sessionId: string | null;                       // null → let the server pick, then pin (X-Queue-Session)
  echo?: () => Promise<ReverbEcho | null>;        // lazy: the site page loads Echo after first paint (default loadEcho)
  initial?: QueueState | null;                    // server-embedded state (REALTIME §8) → first poll is a 304
  onState(s: QueueState): void;
  onEvent?(name: string, payload: unknown): void;
}

type Timer = ReturnType<typeof setTimeout> | undefined;
type PublicChannel = ReturnType<ReverbEcho['channel']>;

export function subscribeQueue(opts: SubscribeQueueOptions): LiveQueueHandle {
  bootConnection(); // idempotent: the site starts its heartbeat only once a live consumer exists
  const getEcho = opts.echo ?? loadEcho;
  let current: QueueState | null = opts.initial ?? null;
  let etag: string | null = current ? `"${current.version}"` : null;
  let sessionId: string | null = opts.sessionId ?? current?.session.id ?? null;
  let pollTimer: Timer;
  let guardTimer: Timer;
  let stopped = false;
  let channel: PublicChannel | null = null;
  let channelName: string | null = null;
  let echoInstance: ReverbEcho | null = null;

  const apply = (next: QueueState, _source: 'ws' | 'poll'): void => {
    if (stopped) return;
    if (current && next.version <= current.version) return;                  // DEDUPE: an older WS frame never overwrites a newer poll (and vice versa)
    current = next;
    etag = `"${next.version}"`;
    opts.onState(next);
    armGuard();
  };

  const poll = async (): Promise<void> => {
    if (stopped || useConnection.getState().mode === 'offline') return;       // offline: nothing to poll; the banner explains
    try {
      const url = `/queue/${opts.doctorSlug}/state` + (sessionId ? `?session=${encodeURIComponent(sessionId)}` : '');
      const res = await fetch(url, { headers: etag ? { 'If-None-Match': etag, Accept: 'application/json' } : { Accept: 'application/json' }, cache: 'no-store', credentials: 'same-origin' });
      const sid = res.headers.get('X-Queue-Session');
      if (sid && !sessionId) { sessionId = sid; await ensureChannel(); }
      if (res.status === 200) apply((await res.json()) as QueueState, 'poll');
      // 304 → nothing to do
    } catch {
      void triggerHeartbeat();                                                 // a failure is evidence for the connection store, not for us
    }
  };

  const schedulePoll = (): void => {
    clearTimeout(pollTimer);
    if (stopped) return;
    const { mode, tabVisible } = useConnection.getState();
    if (mode === 'online' || mode === 'offline') return;                       // online: WS is authoritative; offline: idle
    pollTimer = setTimeout(async () => { await poll(); schedulePoll(); }, tabVisible ? POLL_INTERVAL_MS : POLL_HIDDEN_INTERVAL_MS);
  };

  const armGuard = (): void => {                                              // silent-WS guard: connected but no frame for 90 s while running
    clearTimeout(guardTimer);
    if (stopped || current?.session.status !== 'running') return;
    guardTimer = setTimeout(() => { if (useConnection.getState().mode === 'online') void poll(); armGuard(); }, WS_SILENCE_GUARD_MS);
  };

  const ensureChannel = async (): Promise<void> => {
    if (channel || !sessionId || stopped) return;
    const echo = await getEcho();
    if (!echo || stopped || channel) return;
    echoInstance = echo;
    channelName = tenantChannel.queue(opts.tenantId, sessionId);
    channel = echo.channel(channelName);
    channel
      .listen(`.${QUEUE_EVENTS.state}`, (e: { state: QueueState }) => apply(e.state, 'ws'))
      .listen(`.${QUEUE_EVENTS.serialCalled}`, (e: { version?: number }) => { opts.onEvent?.(QUEUE_EVENTS.serialCalled, e); if (!current || typeof e.version !== 'number' || e.version > current.version) void poll(); }) // small event → fetch the full state once
      .listen(`.${QUEUE_EVENTS.sessionDelayed}`, (e: unknown) => { opts.onEvent?.(QUEUE_EVENTS.sessionDelayed, e); void poll(); })
      .listen(`.${QUEUE_EVENTS.sessionCancelled}`, (e: unknown) => { opts.onEvent?.(QUEUE_EVENTS.sessionCancelled, e); void poll(); })
      .listen(`.${QUEUE_EVENTS.doctorArrived}`, () => { void poll(); })
      .error(() => useConnection.getState().setWsState('failed'));
  };

  // mode transitions drive everything
  const unsub = useConnection.subscribe((s) => s.mode, (mode) => {
    if (mode === 'online')   { void poll(); clearTimeout(pollTimer); }         // CATCH-UP: one immediate poll on (re)connect, then WS only
    if (mode === 'degraded') { void poll(); schedulePoll(); }                  // start 5 s polling
    if (mode === 'offline')  { clearTimeout(pollTimer); }
  });
  const unsubVis = useConnection.subscribe((s) => s.tabVisible, (v) => { if (v) void poll(); schedulePoll(); });

  void poll().then(ensureChannel);
  schedulePoll();
  armGuard();

  return {
    getState: () => current,
    stop: () => {
      stopped = true;
      unsub();
      unsubVis();
      clearTimeout(pollTimer);
      clearTimeout(guardTimer);
      if (channelName) {
        const name = channelName;
        if (echoInstance) echoInstance.leave(name);
        else void getEcho().then((e) => e?.leave(name));
      }
      channel = null;
      channelName = null;
    },
  };
}
