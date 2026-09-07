// resources/js/shared/connection/store.ts — THE network-state model (docs/OFFLINE.md §3, ARCHITECTURE §7.7, BRIEF §5.E/F.1).
// Nothing else may set `mode`. Inputs: navigator.onLine, laravel-echo ConnectionStatus, the /api/ping heartbeat,
// and axios success/ERR_NETWORK evidence — all funnelled through the actions below.
import { create } from 'zustand';
import { subscribeWithSelector } from 'zustand/middleware';
import { HEARTBEAT_FAILS_TO_OFFLINE, WS_DOWN_DEBOUNCE_MS, WS_UP_DEBOUNCE_MS } from './constants';

export type ConnectionMode = 'online' | 'degraded' | 'offline';
export type WsState = 'connected' | 'connecting' | 'disconnected' | 'failed' | 'reconnecting' | 'unknown';
export type SyncPhase = 'idle' | 'syncing' | 'conflicts' | 'error';

export interface ActiveBlock {
  displayFrom: string;
  displayTo: string;
  remaining: number;
}

export interface ConnectionState {
  mode: ConnectionMode;
  since: number;                  // epoch ms of last mode change
  wsState: WsState;
  wsSince: number;
  browserOnline: boolean;
  lastHeartbeatOkAt: number | null;
  consecutiveHeartbeatFailures: number;
  tabVisible: boolean;
  // offline-desk facts surfaced in the indicator (written by the offline module, read by the banner)
  pendingEvents: number;
  conflicts: number;
  syncPhase: SyncPhase;
  activeBlock: ActiveBlock | null;
  // inputs
  setBrowserOnline(v: boolean): void;
  setWsState(s: WsState): void;
  heartbeatResult(ok: boolean): void;
  setTabVisible(v: boolean): void;
  setSyncFacts(p: Partial<Pick<ConnectionState, 'pendingEvents' | 'conflicts' | 'syncPhase' | 'activeBlock'>>): void;
}

export type SyncFacts = Parameters<ConnectionState['setSyncFacts']>[0];

type Timer = ReturnType<typeof setTimeout> | undefined;

let okSinceOffline = false; // a heartbeat/API success observed since we last entered offline (module-scoped so tests can reset it)

export const useConnection = create<ConnectionState>()(
  subscribeWithSelector((set, get) => {
    let wsDownTimer: Timer;
    let wsUpTimer: Timer;

    const armWsTimers = (ws: WsState): void => {
      clearTimeout(wsDownTimer);
      clearTimeout(wsUpTimer);
      if (ws === 'connected') {
        // degraded → online only after the socket has stayed up for WS_UP_DEBOUNCE_MS
        wsUpTimer = setTimeout(() => { if (get().mode === 'degraded' && get().wsState === 'connected') go('online'); }, WS_UP_DEBOUNCE_MS);
      } else {
        // online → degraded only after the socket has stayed down for WS_DOWN_DEBOUNCE_MS
        wsDownTimer = setTimeout(() => { if (get().mode === 'online' && get().wsState !== 'connected') go('degraded'); }, WS_DOWN_DEBOUNCE_MS);
      }
    };

    const go = (mode: ConnectionMode): void => {
      if (get().mode === mode) return;
      if (mode === 'offline') okSinceOffline = false;
      set({ mode, since: Date.now() });
      // Entering degraded while the socket is already up (e.g. offline → degraded after a heartbeat) must still
      // reach online after the up-debounce; the WS state may never change again to re-arm the timer.
      if (mode === 'degraded' && get().wsState === 'connected') armWsTimers('connected');
    };

    const reevaluate = (): void => {
      const s = get();
      if (!s.browserOnline || s.consecutiveHeartbeatFailures >= HEARTBEAT_FAILS_TO_OFFLINE) { go('offline'); return; }
      if (s.mode === 'offline') {
        // never jump straight to online; and only a success observed AFTER we went offline counts
        // (navigator.onLine flipping back to true merely triggers a heartbeat, OFFLINE §3.1)
        if (okSinceOffline) go('degraded');
        return;
      }
      if (s.wsState === 'failed') { go('degraded'); return; }
      // online/degraded decided by the debounced timers armed in setWsState/go
    };

    return {
      mode: 'offline', since: Date.now(), wsState: 'unknown', wsSince: Date.now(),
      browserOnline: typeof navigator === 'undefined' ? true : navigator.onLine,
      lastHeartbeatOkAt: null, consecutiveHeartbeatFailures: 0, tabVisible: true,
      pendingEvents: 0, conflicts: 0, syncPhase: 'idle', activeBlock: null,

      setBrowserOnline: (v) => { set({ browserOnline: v }); reevaluate(); },
      setWsState: (ws) => {
        if (get().wsState !== ws) set({ wsState: ws, wsSince: Date.now() });
        armWsTimers(ws);
        reevaluate();
      },
      heartbeatResult: (ok) => {
        if (ok) okSinceOffline = true;
        set(ok ? { lastHeartbeatOkAt: Date.now(), consecutiveHeartbeatFailures: 0 }
               : { consecutiveHeartbeatFailures: get().consecutiveHeartbeatFailures + 1 });
        reevaluate();
      },
      setTabVisible: (v) => set({ tabVisible: v }),
      setSyncFacts: (p) => set(p),
    };
  }),
);

// Derived selectors (OFFLINE §3.3). Use with useConnection(selectIsLive) — components never read navigator.onLine.
export const selectMode = (s: ConnectionState): ConnectionMode => s.mode;
export const selectCanUseServer = (s: ConnectionState): boolean => s.mode !== 'offline';
export const selectIsLive = (s: ConnectionState): boolean => s.mode === 'online';
export const selectIsPolling = (s: ConnectionState): boolean => s.mode === 'degraded';
export const selectIsOffline = (s: ConnectionState): boolean => s.mode === 'offline';
export const selectSyncFacts = (s: ConnectionState): Pick<ConnectionState, 'pendingEvents' | 'conflicts' | 'syncPhase' | 'activeBlock'> =>
  ({ pendingEvents: s.pendingEvents, conflicts: s.conflicts, syncPhase: s.syncPhase, activeBlock: s.activeBlock });

/** Test seam: back to the boot state (keeps the actions). */
export function resetConnectionForTests(overrides: Partial<Omit<ConnectionState, 'setBrowserOnline' | 'setWsState' | 'heartbeatResult' | 'setTabVisible' | 'setSyncFacts'>> = {}): void {
  okSinceOffline = false;
  useConnection.setState({
    mode: 'offline', since: Date.now(), wsState: 'unknown', wsSince: Date.now(),
    browserOnline: true, lastHeartbeatOkAt: null, consecutiveHeartbeatFailures: 0, tabVisible: true,
    pendingEvents: 0, conflicts: 0, syncPhase: 'idle', activeBlock: null,
    ...overrides,
  });
}
