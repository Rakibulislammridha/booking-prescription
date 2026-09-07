// Every transition rule of docs/OFFLINE.md §3.2, one named test each.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resetConnectionForTests, selectCanUseServer, selectIsLive, selectIsPolling, useConnection } from '../store';
import { HEARTBEAT_FAILS_TO_OFFLINE, WS_DOWN_DEBOUNCE_MS, WS_UP_DEBOUNCE_MS } from '../constants';

const s = () => useConnection.getState();

/** Bring the store to a known mode through the real inputs. */
function reach(mode: 'online' | 'degraded' | 'offline'): void {
  resetConnectionForTests();
  if (mode === 'offline') return;
  s().heartbeatResult(true); // offline → degraded
  if (mode === 'degraded') return;
  s().setWsState('connected');
  vi.advanceTimersByTime(WS_UP_DEBOUNCE_MS); // degraded → online
  expect(s().mode).toBe('online');
}

beforeEach(() => {
  vi.useFakeTimers();
  vi.setSystemTime(new Date('2026-09-06T04:42:00Z'));
  resetConnectionForTests();
});
afterEach(() => vi.useRealTimers());

describe('boot state', () => {
  it('starts offline with an unknown socket and no heartbeat yet', () => {
    expect(s().mode).toBe('offline');
    expect(s().wsState).toBe('unknown');
    expect(s().lastHeartbeatOkAt).toBeNull();
  });
});

describe('any → offline', () => {
  it('navigator.onLine === false ⇒ immediate offline from online', () => {
    reach('online');
    s().setBrowserOnline(false);
    expect(s().mode).toBe('offline');
  });

  it('navigator.onLine === false ⇒ immediate offline from degraded', () => {
    reach('degraded');
    s().setBrowserOnline(false);
    expect(s().mode).toBe('offline');
  });

  it(`${HEARTBEAT_FAILS_TO_OFFLINE} consecutive heartbeat failures ⇒ offline; one failure is not enough`, () => {
    reach('online');
    s().heartbeatResult(false);
    expect(s().mode).toBe('online');
    s().heartbeatResult(false);
    expect(s().mode).toBe('offline');
  });

  it('a success between failures resets the consecutive counter', () => {
    reach('degraded');
    s().heartbeatResult(false);
    s().heartbeatResult(true);
    s().heartbeatResult(false);
    expect(s().mode).toBe('degraded');
    expect(s().consecutiveHeartbeatFailures).toBe(1);
  });

  it('a WebSocket drop alone never means offline', () => {
    reach('online');
    s().setWsState('disconnected');
    vi.advanceTimersByTime(WS_DOWN_DEBOUNCE_MS * 5);
    expect(s().mode).toBe('degraded');
    s().setWsState('failed');
    expect(s().mode).toBe('degraded');
  });
});

describe('offline → degraded', () => {
  it('one successful heartbeat recovers to degraded with no debounce', () => {
    reach('offline');
    s().heartbeatResult(true);
    expect(s().mode).toBe('degraded');
  });

  it('never jumps straight to online, even if the socket is already connected', () => {
    reach('offline');
    s().setWsState('connected');
    expect(s().mode).toBe('offline');
    s().heartbeatResult(true);
    expect(s().mode).toBe('degraded');
  });

  it('navigator.onLine flipping back to true only triggers a heartbeat — it does not change mode by itself', () => {
    reach('degraded');
    s().setBrowserOnline(false);
    expect(s().mode).toBe('offline');
    s().setBrowserOnline(true);
    expect(s().mode).toBe('offline');
    s().heartbeatResult(true);
    expect(s().mode).toBe('degraded');
  });

  it('stays offline while the browser reports offline even if a heartbeat succeeds', () => {
    reach('offline');
    s().setBrowserOnline(false);
    s().heartbeatResult(true);
    expect(s().mode).toBe('offline');
  });

  it('after two failures, a single success (failures reset) recovers to degraded', () => {
    reach('online');
    s().heartbeatResult(false);
    s().heartbeatResult(false);
    expect(s().mode).toBe('offline');
    s().heartbeatResult(true);
    expect(s().mode).toBe('degraded');
    expect(s().consecutiveHeartbeatFailures).toBe(0);
  });
});

describe('degraded → online', () => {
  it(`requires wsState === 'connected' continuously for ${WS_UP_DEBOUNCE_MS} ms`, () => {
    reach('degraded');
    s().setWsState('connected');
    vi.advanceTimersByTime(WS_UP_DEBOUNCE_MS - 1);
    expect(s().mode).toBe('degraded');
    vi.advanceTimersByTime(1);
    expect(s().mode).toBe('online');
  });

  it('a flap inside the up-debounce restarts the timer', () => {
    reach('degraded');
    s().setWsState('connected');
    vi.advanceTimersByTime(WS_UP_DEBOUNCE_MS - 500);
    s().setWsState('reconnecting');
    s().setWsState('connected');
    vi.advanceTimersByTime(WS_UP_DEBOUNCE_MS - 500);
    expect(s().mode).toBe('degraded');
    vi.advanceTimersByTime(500);
    expect(s().mode).toBe('online');
  });

  it('entering degraded while the socket is already connected still reaches online after the debounce', () => {
    reach('online');
    s().heartbeatResult(false);
    s().heartbeatResult(false); // → offline while WS stays 'connected'
    expect(s().mode).toBe('offline');
    s().heartbeatResult(true); // → degraded; no wsState change will follow
    expect(s().mode).toBe('degraded');
    vi.advanceTimersByTime(WS_UP_DEBOUNCE_MS);
    expect(s().mode).toBe('online');
  });
});

describe('online → degraded', () => {
  it(`requires wsState !== 'connected' continuously for ${WS_DOWN_DEBOUNCE_MS} ms`, () => {
    reach('online');
    s().setWsState('disconnected');
    vi.advanceTimersByTime(WS_DOWN_DEBOUNCE_MS - 1);
    expect(s().mode).toBe('online');
    vi.advanceTimersByTime(1);
    expect(s().mode).toBe('degraded');
  });

  it('a reconnect inside the down-debounce keeps the desk online', () => {
    reach('online');
    s().setWsState('reconnecting');
    vi.advanceTimersByTime(WS_DOWN_DEBOUNCE_MS - 1000);
    s().setWsState('connected');
    vi.advanceTimersByTime(WS_DOWN_DEBOUNCE_MS * 2);
    expect(s().mode).toBe('online');
  });

  it("wsState === 'failed' degrades immediately", () => {
    reach('online');
    s().setWsState('failed');
    expect(s().mode).toBe('degraded');
  });
});

describe('bookkeeping and selectors', () => {
  it('records since on every mode change and wsSince on socket changes', () => {
    reach('degraded');
    const before = s().since;
    vi.advanceTimersByTime(5000);
    s().setWsState('connected');
    expect(s().wsSince).toBe(Date.now());
    vi.advanceTimersByTime(WS_UP_DEBOUNCE_MS);
    expect(s().since).toBeGreaterThan(before);
  });

  it('setSyncFacts stores the offline-desk facts without touching mode', () => {
    reach('online');
    s().setSyncFacts({ pendingEvents: 4, conflicts: 1, syncPhase: 'syncing', activeBlock: { displayFrom: 'A-021', displayTo: 'A-030', remaining: 7 } });
    expect(s().mode).toBe('online');
    expect(s().pendingEvents).toBe(4);
    expect(s().activeBlock?.remaining).toBe(7);
  });

  it('selectors derive from mode only', () => {
    reach('online');
    expect(selectIsLive(s())).toBe(true);
    expect(selectIsPolling(s())).toBe(false);
    expect(selectCanUseServer(s())).toBe(true);
    reach('degraded');
    expect(selectIsPolling(s())).toBe(true);
    expect(selectCanUseServer(s())).toBe(true);
    reach('offline');
    expect(selectCanUseServer(s())).toBe(false);
  });
});
