// The in-call control state machine. It is a pure reducer precisely so the awkward paths — a drop mid-call, a
// drop after the call ended, five failed reconnects — can be asserted without a browser or a video service.
import { describe, expect, it } from 'vitest';
import {
  callReducer,
  canJoin,
  durationSeconds,
  initialCallState,
  isLive,
  MAX_RECONNECT_ATTEMPTS,
  qualityFromStats,
  type CallEvent,
  type CallState,
} from '../core/callMachine';

const run = (events: CallEvent[], from: CallState = initialCallState): CallState => events.reduce(callReducer, from);

describe('the happy path', () => {
  it('walks idle → preflight → ready → connecting → connected', () => {
    const events: CallEvent[] = [
      { type: 'preflight.start' },
      { type: 'preflight.ok', camera: true },
      { type: 'join' },
      { type: 'connected', at: 1_000 },
    ];
    const phases: string[] = [initialCallState.phase];
    let state = initialCallState;

    for (const event of events) {
      state = callReducer(state, event);
      phases.push(state.phase);
    }

    expect(phases).toEqual(['idle', 'preflight', 'ready', 'connecting', 'connected']);
    expect(state.startedAt).toBe(1_000);
    expect(isLive(state)).toBe(true);
  });

  it('keeps the camera off when the pre-flight found no camera', () => {
    const state = run([{ type: 'preflight.start' }, { type: 'preflight.ok', camera: false }]);

    expect(state.phase).toBe('ready');
    expect(state.camEnabled).toBe(false);
  });

  it('fails the pre-flight with the reason the report gave', () => {
    const state = run([{ type: 'preflight.start' }, { type: 'preflight.fail', reason: 'telemedicine.preflight.denied' }]);

    expect(state.phase).toBe('failed');
    expect(state.errorKey).toBe('telemedicine.preflight.denied');
  });
});

describe('joining', () => {
  it('is possible only once the room is open and the devices are ready', () => {
    const ready = run([{ type: 'preflight.start' }, { type: 'preflight.ok', camera: true }]);

    expect(canJoin(ready, false)).toBe(false);
    expect(canJoin(ready, true)).toBe(true);
    expect(canJoin(initialCallState, true)).toBe(false);
    expect(canJoin(run([{ type: 'fail', reason: 'x' }]), true)).toBe(true);
  });

  it('ignores a second join while one is already in flight', () => {
    const connecting = run([{ type: 'join' }]);
    expect(callReducer(connecting, { type: 'join' })).toBe(connecting);

    const connected = run([{ type: 'join' }, { type: 'connected' }]);
    expect(callReducer(connected, { type: 'join' })).toBe(connected);
  });
});

describe('the reconnect path', () => {
  it('a drop becomes reconnecting and keeps the mic/camera choices', () => {
    const state = run([{ type: 'join' }, { type: 'connected', at: 10 }, { type: 'toggle.mic' }, { type: 'remote', present: true }, { type: 'dropped' }]);

    expect(state.phase).toBe('reconnecting');
    expect(state.attempts).toBe(1);
    expect(state.micEnabled).toBe(false);
    expect(state.remotePresent).toBe(false);
    expect(state.startedAt).toBe(10);
    expect(isLive(state)).toBe(true);
  });

  it('reconnecting to the same call keeps the original start time', () => {
    const state = run([{ type: 'join' }, { type: 'connected', at: 10 }, { type: 'dropped' }, { type: 'retry' }, { type: 'connected', at: 9_999 }]);

    expect(state.phase).toBe('connected');
    expect(state.startedAt).toBe(10);
    expect(state.attempts).toBe(0);
  });

  it('gives up after the attempt budget rather than spinning forever', () => {
    let state = run([{ type: 'join' }, { type: 'connected' }]);
    for (let i = 0; i < MAX_RECONNECT_ATTEMPTS; i += 1) state = callReducer(state, { type: 'dropped' });

    expect(state.phase).toBe('reconnecting');
    expect(state.attempts).toBe(MAX_RECONNECT_ATTEMPTS);

    state = callReducer(state, { type: 'dropped' });
    expect(state.phase).toBe('failed');
    expect(state.errorKey).toBe('telemedicine.call.error.lost');
  });

  it('a drop after the call ended is ignored — a closing socket is not a resurrection', () => {
    const ended = run([{ type: 'join' }, { type: 'connected' }, { type: 'end', at: 5_000 }]);

    expect(callReducer(ended, { type: 'dropped' })).toBe(ended);
    expect(callReducer(ended, { type: 'retry' })).toBe(ended);
    expect(callReducer(initialCallState, { type: 'dropped' })).toBe(initialCallState);
  });
});

describe('controls', () => {
  it('toggles are independent and survive every phase', () => {
    const state = run([{ type: 'toggle.mic' }, { type: 'toggle.cam' }, { type: 'toggle.cam' }]);

    expect(state.micEnabled).toBe(false);
    expect(state.camEnabled).toBe(true);
  });

  it('ending a call clears recording and presence and stamps the end', () => {
    const state = run([{ type: 'join' }, { type: 'connected', at: 1_000 }, { type: 'recording', on: true }, { type: 'remote', present: true }, { type: 'end', at: 4_000 }]);

    expect(state.phase).toBe('ended');
    expect(state.recording).toBe(false);
    expect(state.remotePresent).toBe(false);
    expect(durationSeconds(state, 999_999)).toBe(3);
  });

  it('reports a live duration while the call runs', () => {
    const state = run([{ type: 'join' }, { type: 'connected', at: 1_000 }]);

    expect(durationSeconds(state, 4_500)).toBe(3);
    expect(durationSeconds(initialCallState, 4_500)).toBe(0);
  });
});

describe('quality grading', () => {
  it('grades a Bangladeshi mobile link, not a datacentre one', () => {
    expect(qualityFromStats({})).toBe('good');
    expect(qualityFromStats({ packetLossPct: 1, rttMs: 120, bitrateKbps: 600 })).toBe('good');
    expect(qualityFromStats({ packetLossPct: 4 })).toBe('fair');
    expect(qualityFromStats({ rttMs: 350 })).toBe('fair');
    expect(qualityFromStats({ bitrateKbps: 200 })).toBe('fair');
    expect(qualityFromStats({ packetLossPct: 12 })).toBe('poor');
    expect(qualityFromStats({ rttMs: 900 })).toBe('poor');
    expect(qualityFromStats({ bitrateKbps: 40 })).toBe('poor');
  });

  it('feeds the machine', () => {
    expect(run([{ type: 'quality', stats: { packetLossPct: 9 } }]).quality).toBe('poor');
    expect(run([{ type: 'quality', stats: { packetLossPct: 0 } }]).quality).toBe('good');
  });
});
