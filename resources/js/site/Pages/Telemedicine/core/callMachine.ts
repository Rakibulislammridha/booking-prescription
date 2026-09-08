// The in-call control state machine. One pure reducer, shared by the patient's room and the doctor's rail, so a
// "reconnecting" badge means the same thing on both screens and both are covered by one Vitest suite.
//
// The point of making it a machine rather than a bag of booleans is the reconnect path: a dropped socket must
// not look like a finished consultation, must keep the mic/camera choices the user already made, and must stop
// retrying at some point rather than spinning forever on a train.

export type CallPhase =
  | 'idle'          // nothing asked for yet
  | 'preflight'     // devices being checked
  | 'ready'         // devices fine, waiting for the room to open (or for the user to press Join)
  | 'connecting'
  | 'connected'
  | 'reconnecting'
  | 'ended'         // finished normally
  | 'failed';       // gave up

export type CallQuality = 'unknown' | 'good' | 'fair' | 'poor';

export interface QualityStats {
  packetLossPct?: number;
  rttMs?: number;
  bitrateKbps?: number;
}

export interface CallState {
  phase: CallPhase;
  micEnabled: boolean;
  camEnabled: boolean;
  recording: boolean;
  quality: CallQuality;
  /** True once the OTHER participant is in the room. Drives "the doctor is not here yet". */
  remotePresent: boolean;
  attempts: number;
  errorKey: string | null;
  startedAt: number | null;
  endedAt: number | null;
}

export type CallEvent =
  | { type: 'preflight.start' }
  | { type: 'preflight.ok'; camera: boolean }
  | { type: 'preflight.fail'; reason: string }
  | { type: 'join' }
  | { type: 'connected'; at?: number }
  | { type: 'remote'; present: boolean }
  | { type: 'dropped' }
  | { type: 'retry' }
  | { type: 'toggle.mic' }
  | { type: 'toggle.cam' }
  | { type: 'recording'; on: boolean }
  | { type: 'quality'; stats: QualityStats }
  | { type: 'end'; at?: number }
  | { type: 'fail'; reason: string };

export const MAX_RECONNECT_ATTEMPTS = 5;

export const initialCallState: CallState = {
  phase: 'idle',
  micEnabled: true,
  camEnabled: true,
  recording: false,
  quality: 'unknown',
  remotePresent: false,
  attempts: 0,
  errorKey: null,
  startedAt: null,
  endedAt: null,
};

/** Thresholds are deliberately generous: this grades a Bangladeshi mobile connection, not a datacentre link. */
export function qualityFromStats(stats: QualityStats): CallQuality {
  const loss = stats.packetLossPct ?? 0;
  const rtt = stats.rttMs ?? 0;
  const bitrate = stats.bitrateKbps;
  if (loss >= 8 || rtt >= 600 || (bitrate !== undefined && bitrate < 80)) return 'poor';
  if (loss >= 3 || rtt >= 300 || (bitrate !== undefined && bitrate < 250)) return 'fair';
  return 'good';
}

export function isLive(state: CallState): boolean {
  return state.phase === 'connected' || state.phase === 'reconnecting';
}

export function canJoin(state: CallState, roomOpen: boolean): boolean {
  return roomOpen && (state.phase === 'ready' || state.phase === 'failed');
}

export function durationSeconds(state: CallState, now: number): number {
  if (state.startedAt === null) return 0;
  return Math.max(0, Math.floor(((state.endedAt ?? now) - state.startedAt) / 1000));
}

export function callReducer(state: CallState, event: CallEvent): CallState {
  switch (event.type) {
    case 'preflight.start':
      return { ...state, phase: 'preflight', errorKey: null };

    case 'preflight.ok':
      // A missing camera is not a failure: the consultation continues on audio, and the camera toggle goes away.
      return { ...state, phase: 'ready', errorKey: null, camEnabled: state.camEnabled && event.camera };

    case 'preflight.fail':
      return { ...state, phase: 'failed', errorKey: event.reason };

    case 'join':
      return state.phase === 'connecting' || state.phase === 'connected'
        ? state
        : { ...state, phase: 'connecting', errorKey: null };

    case 'connected':
      return {
        ...state,
        phase: 'connected',
        attempts: 0,
        errorKey: null,
        endedAt: null,
        startedAt: state.startedAt ?? event.at ?? Date.now(),
      };

    case 'remote':
      return { ...state, remotePresent: event.present };

    case 'dropped':
      // A drop after the call ended is noise from a closing socket, never a resurrection.
      if (state.phase === 'ended' || state.phase === 'idle') return state;
      return state.attempts + 1 > MAX_RECONNECT_ATTEMPTS
        ? { ...state, phase: 'failed', errorKey: 'telemedicine.call.error.lost', remotePresent: false }
        : { ...state, phase: 'reconnecting', attempts: state.attempts + 1, remotePresent: false };

    case 'retry':
      return state.phase === 'ended' ? state : { ...state, phase: 'connecting', errorKey: null };

    case 'toggle.mic':
      return { ...state, micEnabled: !state.micEnabled };

    case 'toggle.cam':
      return { ...state, camEnabled: !state.camEnabled };

    case 'recording':
      return { ...state, recording: event.on };

    case 'quality':
      return { ...state, quality: qualityFromStats(event.stats) };

    case 'end':
      return { ...state, phase: 'ended', remotePresent: false, recording: false, endedAt: event.at ?? Date.now() };

    case 'fail':
      return { ...state, phase: 'failed', errorKey: event.reason };

    default:
      return state;
  }
}
