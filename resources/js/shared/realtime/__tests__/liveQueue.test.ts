// subscribeQueue(): WS-or-poll with version dedupe (docs/REALTIME.md §6) — mock Echo channel + mock fetch.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resetConnectionForTests, useConnection } from '../../connection/store';
import { stopHeartbeat } from '../../connection/heartbeat';
import { POLL_INTERVAL_MS, POLL_HIDDEN_INTERVAL_MS, WS_SILENCE_GUARD_MS, subscribeQueue } from '../liveQueue';
import type { QueueState } from '../types';
import type { ReverbEcho } from '../echo';

// subscribeQueue() boots the heartbeat; here the store is driven by hand, so keep the timer out of the way.
vi.mock('../../connection/boot', () => ({ bootConnection: vi.fn() }));

function state(version: number, status: QueueState['session']['status'] = 'running'): QueueState {
  return {
    v: 1,
    session: { id: 'ses1', code: 'A', date: '2026-09-06', status, mode: 'serial', planned_start_at: '2026-09-06T03:00:00Z', expected_start_at: '2026-09-06T03:00:00Z', delay_minutes: 0, doctor: { id: 'doc1', slug: 'dr-rahman', name: 'Dr Rahman', name_bn: null, room: null }, branch: { id: 'br1', name: 'Main' } },
    now_serving: null, last_called: [], counts: { booked: 0, checked_in: 0, in_consultation: 0, completed: 0, no_show: 0, cancelled: 0, postponed: 0, waiting: 0 },
    avg_consult_seconds: 300, eta_confidence: 'normal', serials: [], updated_at: '2026-09-06T04:42:00Z', version,
  };
}

type Listener = (payload: unknown) => void;

function mockEcho() {
  const listeners = new Map<string, Listener>();
  const channel = {
    listen: vi.fn((event: string, cb: Listener) => { listeners.set(event, cb); return channel; }),
    error: vi.fn(() => channel),
  };
  const echo = { channel: vi.fn(() => channel), leave: vi.fn() } as unknown as ReverbEcho;
  const emit = (event: string, payload: unknown): void => { listeners.get(event)?.(payload); };
  return { echo, channel, emit, listeners };
}

function jsonResponse(body: QueueState, session = 'ses1'): Response {
  return { status: 200, headers: new Headers({ 'X-Queue-Session': session, ETag: `"${body.version}"` }), json: () => Promise.resolve(body) } as unknown as Response;
}
const notModified = (): Response => ({ status: 304, headers: new Headers({ 'X-Queue-Session': 'ses1' }), json: () => Promise.reject(new Error('no body')) } as unknown as Response);

beforeEach(() => {
  vi.useFakeTimers();
  resetConnectionForTests({ mode: 'degraded', lastHeartbeatOkAt: Date.now() });
  // the heartbeat ping must not interfere with the poll fetch mock
  vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(notModified())));
});
afterEach(() => {
  stopHeartbeat();
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

const isPoll = (call: unknown[]): boolean => String(call[0]).startsWith('/queue/');
const pollCalls = (fetchMock: ReturnType<typeof vi.fn>) => fetchMock.mock.calls.filter(isPoll);

describe('subscribeQueue', () => {
  it('polls immediately, pins the session from X-Queue-Session, and sends If-None-Match afterwards', async () => {
    const fetchMock = vi.fn((url: string) => Promise.resolve(String(url).startsWith('/queue/') ? jsonResponse(state(10)) : ({ ok: true, status: 200 } as Response)));
    vi.stubGlobal('fetch', fetchMock);
    const onState = vi.fn();
    const { echo } = mockEcho();
    const handle = subscribeQueue({ tenantId: 'ten1', doctorSlug: 'dr-rahman', sessionId: null, echo: () => Promise.resolve(echo), onState });
    await vi.advanceTimersByTimeAsync(0);
    expect(pollCalls(fetchMock)[0]?.[0]).toBe('/queue/dr-rahman/state');
    expect(onState).toHaveBeenCalledWith(expect.objectContaining({ version: 10 }));
    expect(handle.getState()?.version).toBe(10);

    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS);
    const second = pollCalls(fetchMock)[1] as [string, RequestInit];
    expect(second[0]).toBe('/queue/dr-rahman/state?session=ses1');
    expect((second[1].headers as Record<string, string>)['If-None-Match']).toBe('"10"');
    handle.stop();
  });

  it('DEDUPE: an older WS frame never overwrites a newer poll, and an older poll never overwrites a newer WS frame', async () => {
    const fetchMock = vi.fn((url: string) => Promise.resolve(String(url).startsWith('/queue/') ? jsonResponse(state(10)) : ({ ok: true, status: 200 } as Response)));
    vi.stubGlobal('fetch', fetchMock);
    const onState = vi.fn();
    const m = mockEcho();
    const handle = subscribeQueue({ tenantId: 'ten1', doctorSlug: 'dr-rahman', sessionId: 'ses1', echo: () => Promise.resolve(m.echo), onState });
    await vi.advanceTimersByTimeAsync(0);
    expect(handle.getState()?.version).toBe(10);
    expect(m.echo.channel).toHaveBeenCalledWith('tenant.ten1.queue.ses1');

    m.emit('.queue.state', { state: state(9) });   // stale frame
    expect(handle.getState()?.version).toBe(10);
    m.emit('.queue.state', { state: state(12) });  // newer frame
    expect(handle.getState()?.version).toBe(12);
    m.emit('.queue.state', { state: state(12) });  // duplicate
    expect(onState).toHaveBeenCalledTimes(2);

    fetchMock.mockImplementation((url: string) => Promise.resolve(String(url).startsWith('/queue/') ? jsonResponse(state(11)) : ({ ok: true, status: 200 } as Response)));
    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS);
    expect(handle.getState()?.version).toBe(12);   // older poll ignored
    expect(onState).toHaveBeenCalledTimes(2);
    handle.stop();
  });

  it('polls every 5 s in degraded, 30 s when hidden, stops in online and offline, catch-up poll on reconnect', async () => {
    const fetchMock = vi.fn((url: string) => Promise.resolve(String(url).startsWith('/queue/') ? notModified() : ({ ok: true, status: 200 } as Response)));
    vi.stubGlobal('fetch', fetchMock);
    const { echo } = mockEcho();
    const handle = subscribeQueue({ tenantId: 'ten1', doctorSlug: 'dr-rahman', sessionId: 'ses1', initial: state(5), echo: () => Promise.resolve(echo), onState: vi.fn() });
    await vi.advanceTimersByTimeAsync(0);
    expect(pollCalls(fetchMock)).toHaveLength(1);
    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS * 3);
    expect(pollCalls(fetchMock)).toHaveLength(4);

    useConnection.getState().setTabVisible(false);
    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS * 3);
    expect(pollCalls(fetchMock)).toHaveLength(4);          // hidden: no 5 s polls
    await vi.advanceTimersByTimeAsync(POLL_HIDDEN_INTERVAL_MS - POLL_INTERVAL_MS * 3);
    expect(pollCalls(fetchMock)).toHaveLength(5);          // one 30 s poll
    useConnection.getState().setTabVisible(true);
    await vi.advanceTimersByTimeAsync(0);
    expect(pollCalls(fetchMock)).toHaveLength(6);          // immediate poll on becoming visible

    useConnection.setState({ mode: 'online' });
    await vi.advanceTimersByTimeAsync(0);
    expect(pollCalls(fetchMock)).toHaveLength(7);          // catch-up poll
    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS * 4);
    expect(pollCalls(fetchMock)).toHaveLength(7);          // WS is authoritative: no timer

    useConnection.setState({ mode: 'offline' });
    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS * 4);
    expect(pollCalls(fetchMock)).toHaveLength(7);          // offline: idle

    useConnection.setState({ mode: 'degraded' });
    await vi.advanceTimersByTimeAsync(0);
    expect(pollCalls(fetchMock)).toHaveLength(8);          // resumes with an immediate poll
    handle.stop();
  });

  it('a small serial.called event with a newer version triggers one full-state poll; a channel error marks the socket failed', async () => {
    const fetchMock = vi.fn((url: string) => Promise.resolve(String(url).startsWith('/queue/') ? jsonResponse(state(10)) : ({ ok: true, status: 200 } as Response)));
    vi.stubGlobal('fetch', fetchMock);
    const m = mockEcho();
    const onEvent = vi.fn();
    useConnection.setState({ mode: 'online' });
    const handle = subscribeQueue({ tenantId: 'ten1', doctorSlug: 'dr-rahman', sessionId: 'ses1', echo: () => Promise.resolve(m.echo), onState: vi.fn(), onEvent });
    await vi.advanceTimersByTimeAsync(0);
    const before = pollCalls(fetchMock).length;
    m.emit('.serial.called', { version: 11, c: 'A-042' });
    expect(onEvent).toHaveBeenCalledWith('serial.called', expect.objectContaining({ c: 'A-042' }));
    await vi.advanceTimersByTimeAsync(0);
    expect(pollCalls(fetchMock)).toHaveLength(before + 1);
    m.emit('.serial.called', { version: 10 });             // not newer → no poll
    await vi.advanceTimersByTimeAsync(0);
    expect(pollCalls(fetchMock)).toHaveLength(before + 1);

    const errorCb = (m.channel.error.mock.calls[0] as unknown as [() => void])[0];
    errorCb();
    expect(useConnection.getState().wsState).toBe('failed');
    handle.stop();
    expect(m.echo.leave).toHaveBeenCalledWith('tenant.ten1.queue.ses1');
  });

  it('stop() ends polling and ignores late frames', async () => {
    const fetchMock = vi.fn((url: string) => Promise.resolve(String(url).startsWith('/queue/') ? jsonResponse(state(10)) : ({ ok: true, status: 200 } as Response)));
    vi.stubGlobal('fetch', fetchMock);
    const m = mockEcho();
    const onState = vi.fn();
    const handle = subscribeQueue({ tenantId: 'ten1', doctorSlug: 'dr-rahman', sessionId: 'ses1', echo: () => Promise.resolve(m.echo), onState });
    await vi.advanceTimersByTimeAsync(0);
    handle.stop();
    const n = pollCalls(fetchMock).length;
    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS * 3);
    expect(pollCalls(fetchMock)).toHaveLength(n);
    m.emit('.queue.state', { state: state(99) });
    expect(onState).toHaveBeenCalledTimes(1);
  });
  it('the silent-WS guard polls once after 90 s while the session is running, and never when it is not', async () => {
    const fetchMock = vi.fn((url: string) => Promise.resolve(String(url).startsWith('/queue/') ? notModified() : ({ ok: true, status: 200 } as Response)));
    vi.stubGlobal('fetch', fetchMock);
    const { echo } = mockEcho();
    useConnection.setState({ mode: 'online' });

    const running = subscribeQueue({ tenantId: 'ten1', doctorSlug: 'dr-rahman', sessionId: 'ses1', initial: state(5, 'running'), echo: () => Promise.resolve(echo), onState: vi.fn() });
    await vi.advanceTimersByTimeAsync(0);
    const afterSubscribe = pollCalls(fetchMock).length;
    await vi.advanceTimersByTimeAsync(WS_SILENCE_GUARD_MS - 1_000);
    expect(pollCalls(fetchMock)).toHaveLength(afterSubscribe);          // connected and quiet: nothing yet
    await vi.advanceTimersByTimeAsync(1_000);
    expect(pollCalls(fetchMock)).toHaveLength(afterSubscribe + 1);      // one sanity poll
    running.stop();

    fetchMock.mockClear();
    const closed = subscribeQueue({ tenantId: 'ten1', doctorSlug: 'dr-rahman', sessionId: 'ses1', initial: state(5, 'closed'), echo: () => Promise.resolve(echo), onState: vi.fn() });
    await vi.advanceTimersByTimeAsync(0);
    const base = pollCalls(fetchMock).length;
    await vi.advanceTimersByTimeAsync(WS_SILENCE_GUARD_MS * 2);
    expect(pollCalls(fetchMock)).toHaveLength(base);                    // a closed session is not watched
    closed.stop();
  });

  it('offline stops polling and keeps the last state on screen', async () => {
    const fetchMock = vi.fn((url: string) => Promise.resolve(String(url).startsWith('/queue/') ? jsonResponse(state(20)) : ({ ok: true, status: 200 } as Response)));
    vi.stubGlobal('fetch', fetchMock);
    const { echo } = mockEcho();
    const onState = vi.fn();
    const handle = subscribeQueue({ tenantId: 'ten1', doctorSlug: 'dr-rahman', sessionId: 'ses1', echo: () => Promise.resolve(echo), onState });
    await vi.advanceTimersByTimeAsync(0);
    expect(handle.getState()?.version).toBe(20);

    useConnection.setState({ mode: 'offline' });
    const n = pollCalls(fetchMock).length;
    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS * 10);
    expect(pollCalls(fetchMock)).toHaveLength(n);
    expect(handle.getState()?.version).toBe(20);                        // "showing the queue as of 10:42"
    handle.stop();
  });

  it('session.delayed, session.cancelled and doctor.arrived each trigger a full-state poll', async () => {
    const fetchMock = vi.fn((url: string) => Promise.resolve(String(url).startsWith('/queue/') ? notModified() : ({ ok: true, status: 200 } as Response)));
    vi.stubGlobal('fetch', fetchMock);
    const m = mockEcho();
    const onEvent = vi.fn();
    useConnection.setState({ mode: 'online' });
    const handle = subscribeQueue({ tenantId: 'ten1', doctorSlug: 'dr-rahman', sessionId: 'ses1', echo: () => Promise.resolve(m.echo), onState: vi.fn(), onEvent });
    await vi.advanceTimersByTimeAsync(0);
    let expected = pollCalls(fetchMock).length;

    for (const event of ['.session.delayed', '.session.cancelled', '.doctor.arrived']) {
      m.emit(event, { version: 99 });
      await vi.advanceTimersByTimeAsync(0);
      expected += 1;
      expect(pollCalls(fetchMock)).toHaveLength(expected);
    }

    expect(onEvent).toHaveBeenCalledWith('session.delayed', expect.anything());
    expect(onEvent).toHaveBeenCalledWith('session.cancelled', expect.anything());
    handle.stop();
  });
});
