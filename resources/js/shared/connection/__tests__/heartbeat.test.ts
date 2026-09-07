import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resetConnectionForTests, useConnection } from '../store';
import { nextHeartbeatDelay, ping, startHeartbeat, stopHeartbeat, triggerHeartbeat } from '../heartbeat';
import { HEARTBEAT_HIDDEN_MULTIPLIER, HEARTBEAT_INTERVAL_MS, HEARTBEAT_JITTER_MS } from '../constants';

const okResponse = () => Promise.resolve({ ok: true, status: 200 } as Response);

beforeEach(() => {
  vi.useFakeTimers();
  resetConnectionForTests();
});
afterEach(() => {
  stopHeartbeat();
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

describe('ping', () => {
  it('GETs /api/ping with no-store and reports ok', async () => {
    const fetchMock = vi.fn(okResponse);
    vi.stubGlobal('fetch', fetchMock);
    await expect(ping('')).resolves.toBe(true);
    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toBe('/api/ping');
    expect(init.cache).toBe('no-store');
    expect(init.signal).toBeInstanceOf(AbortSignal);
  });

  it('treats a non-2xx as unreachable and a thrown fetch as unreachable', async () => {
    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({ ok: false, status: 502 } as Response)));
    await expect(ping('')).resolves.toBe(false);
    vi.stubGlobal('fetch', vi.fn(() => Promise.reject(new TypeError('Failed to fetch'))));
    await expect(ping('')).resolves.toBe(false);
  });

  it('aborts after the timeout', async () => {
    vi.stubGlobal('fetch', vi.fn((_: string, init: RequestInit) => new Promise<Response>((_resolve, reject) => {
      init.signal?.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
    })));
    const p = ping('', 100);
    await vi.advanceTimersByTimeAsync(101);
    await expect(p).resolves.toBe(false);
  });
});

describe('schedule', () => {
  it('uses the per-mode interval ± jitter and multiplies by 4 while hidden', () => {
    useConnection.setState({ mode: 'degraded', tabVisible: true });
    const d = nextHeartbeatDelay();
    expect(d).toBeGreaterThanOrEqual(HEARTBEAT_INTERVAL_MS.degraded - HEARTBEAT_JITTER_MS);
    expect(d).toBeLessThanOrEqual(HEARTBEAT_INTERVAL_MS.degraded + HEARTBEAT_JITTER_MS);
    useConnection.setState({ mode: 'online', tabVisible: false });
    const hidden = nextHeartbeatDelay();
    expect(hidden).toBeGreaterThanOrEqual(HEARTBEAT_INTERVAL_MS.online * HEARTBEAT_HIDDEN_MULTIPLIER - HEARTBEAT_JITTER_MS);
  });

  it('startHeartbeat beats immediately and feeds the store; extra beats on visible / online / ws connected', async () => {
    const fetchMock = vi.fn(okResponse);
    vi.stubGlobal('fetch', fetchMock);
    startHeartbeat('');
    await vi.advanceTimersByTimeAsync(0);
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(useConnection.getState().mode).toBe('degraded');

    useConnection.getState().setTabVisible(false);
    useConnection.getState().setTabVisible(true);
    await vi.advanceTimersByTimeAsync(0);
    expect(fetchMock).toHaveBeenCalledTimes(2);

    useConnection.getState().setWsState('connected');
    await vi.advanceTimersByTimeAsync(0);
    expect(fetchMock).toHaveBeenCalledTimes(3);

    useConnection.getState().setBrowserOnline(false);
    useConnection.getState().setBrowserOnline(true);
    await vi.advanceTimersByTimeAsync(0);
    expect(fetchMock).toHaveBeenCalledTimes(4);
  });

  it('triggerHeartbeat is a no-op before start and coalesces concurrent beats', async () => {
    await expect(triggerHeartbeat()).resolves.toBe(false);
    let resolve: ((r: Response) => void) | undefined;
    const fetchMock = vi.fn(() => new Promise<Response>((r) => { resolve = r; }));
    vi.stubGlobal('fetch', fetchMock);
    startHeartbeat('');
    const a = triggerHeartbeat();
    const b = triggerHeartbeat();
    expect(fetchMock).toHaveBeenCalledTimes(1);
    resolve?.({ ok: true, status: 200 } as Response);
    await expect(Promise.all([a, b])).resolves.toEqual([true, true]);
  });
});
