// One hook, two surfaces. The patient's room and the doctor's rail differ in layout and in which endpoints they
// call — not in how a call behaves — so the behaviour lives here and the pages pass the four verbs in.
//
// It owns exactly four things: the pre-flight report, the callMachine state, the lazily-loaded video client and
// the room-state poll. Everything else (queue state, the prescription writer, layout) belongs to the pages.
import { useCallback, useEffect, useMemo, useReducer, useRef, useState } from 'react';
import { callReducer, initialCallState, type CallEvent, type CallState } from './callMachine';
import { IDLE_REPORT, runPreflight, stopStream, type PreflightReport } from './preflight';
import type { VideoClient, VideoCredentials } from './videoClient';
import type { TelemedicineRoomState } from '@shared/types/models';

export const POLL_WAITING_MS = 5_000;
export const POLL_IN_CALL_MS = 15_000;

export interface UseCallOptions {
  room: TelemedicineRoomState;
  fetchState(): Promise<TelemedicineRoomState>;
  fetchToken(): Promise<VideoCredentials>;
  reportLeft(): Promise<unknown>;
  reportQuality(stats: { avg_bitrate_kbps?: number; packet_loss_pct?: number; rtt_ms?: number }): Promise<unknown>;
  /** Join the moment the room opens and the devices are ready — what a patient expects, and what a doctor does not. */
  autoJoin?: boolean;
  enabled?: boolean;
}

export interface UseCallResult {
  roomState: TelemedicineRoomState;
  call: CallState;
  preflight: PreflightReport;
  preflightBusy: boolean;
  localStream: MediaStream | null;
  remoteStream: MediaStream | null;
  frameRef: (node: HTMLDivElement | null) => void;
  runPreflightNow(video?: boolean): Promise<void>;
  join(): Promise<void>;
  leave(): Promise<void>;
  dispatch(event: CallEvent): void;
}

export function useCall({ room, fetchState, fetchToken, reportLeft, reportQuality, autoJoin = false, enabled = true }: UseCallOptions): UseCallResult {
  const [roomState, setRoomState] = useState<TelemedicineRoomState>(room);
  const [call, dispatch] = useReducer(callReducer, initialCallState);
  const [preflight, setPreflight] = useState<PreflightReport>(IDLE_REPORT);
  const [preflightBusy, setPreflightBusy] = useState(false);
  const [localStream, setLocalStream] = useState<MediaStream | null>(null);
  const [remoteStream, setRemoteStream] = useState<MediaStream | null>(null);
  const clientRef = useRef<VideoClient | null>(null);
  const frameNode = useRef<HTMLDivElement | null>(null);
  const streamRef = useRef<MediaStream | null>(null);
  const callRef = useRef(call);
  callRef.current = call;

  const frameRef = useCallback((node: HTMLDivElement | null) => { frameNode.current = node; }, []);

  // An Inertia navigation re-renders this component with FRESH props but does NOT re-run useState, so the
  // document has to be adopted explicitly — otherwise the doctor presses "start the call", the server opens the
  // room, and the rail keeps rendering the stale "scheduled" state until the next poll five seconds later.
  const signature = [room.room, room.status, room.opened_at, room.ended_at, room.can_join, room.presence.doctor, room.presence.patient].join('|');
  const roomRef = useRef(room);
  roomRef.current = room;
  useEffect(() => { setRoomState(roomRef.current); }, [signature]);

  const runPreflightNow = useCallback(async (video = true): Promise<void> => {
    setPreflightBusy(true);
    dispatch({ type: 'preflight.start' });
    stopStream(streamRef.current);
    const report = await runPreflight({ video });
    streamRef.current = report.stream;
    setLocalStream(report.stream);
    setPreflight(report);
    setPreflightBusy(false);
    if (report.canJoin) dispatch({ type: 'preflight.ok', camera: report.hasCamera });
    else dispatch({ type: 'preflight.fail', reason: report.messageKey });
  }, []);

  const join = useCallback(async (): Promise<void> => {
    if (callRef.current.phase === 'connecting' || callRef.current.phase === 'connected') return;
    dispatch({ type: 'join' });
    try {
      const credentials = await fetchToken();
      const { loadVideoClient } = await import('./videoClient');
      const client = await loadVideoClient({
        credentials,
        container: frameNode.current,
        handlers: {
          onState: (state) => {
            if (state === 'connected') dispatch({ type: 'connected' });
            if (state === 'reconnecting') dispatch({ type: 'dropped' });
            if (state === 'disconnected') dispatch({ type: 'dropped' });
          },
          onRemote: (present, stream) => { dispatch({ type: 'remote', present }); setRemoteStream(stream); },
          onQuality: (stats) => dispatch({ type: 'quality', stats }),
          onError: () => dispatch({ type: 'fail', reason: 'telemedicine.call.error.provider' }),
        },
      });
      clientRef.current = client;
      await client.connect(credentials, streamRef.current);
      client.setMicrophone(callRef.current.micEnabled);
      client.setCamera(callRef.current.camEnabled);
    } catch {
      dispatch({ type: 'fail', reason: 'telemedicine.call.error.join' });
    }
  }, [fetchToken]);

  const leave = useCallback(async (): Promise<void> => {
    await clientRef.current?.disconnect().catch(() => undefined);
    clientRef.current = null;
    dispatch({ type: 'end' });
    stopStream(streamRef.current);
    streamRef.current = null;
    setLocalStream(null);
    setRemoteStream(null);
    await reportLeft().catch(() => undefined);
  }, [reportLeft]);

  // Mic/camera toggles are applied to the live client as a side effect of the machine, so the UI never has to
  // remember to do both.
  useEffect(() => { clientRef.current?.setMicrophone(call.micEnabled); }, [call.micEnabled]);
  useEffect(() => { clientRef.current?.setCamera(call.camEnabled); }, [call.camEnabled]);

  // The room-state poll: fast while waiting (the patient is watching for "the doctor is here"), slow once
  // connected (the call itself is the source of truth by then).
  useEffect(() => {
    if (!enabled) return undefined;
    let stopped = false;
    let timer: ReturnType<typeof setTimeout> | undefined;

    const tick = async (): Promise<void> => {
      try {
        const next = await fetchState();
        if (stopped) return;
        setRoomState(next);
        const other = next.viewer === 'doctor' ? next.presence.patient : next.presence.doctor;
        dispatch({ type: 'remote', present: other });
        if (next.status === 'ended' || next.status === 'cancelled') dispatch({ type: 'end' });
      } catch {
        /* a failed poll is not a failed call; the next tick tries again */
      }
      if (!stopped) timer = setTimeout(() => void tick(), callRef.current.phase === 'connected' ? POLL_IN_CALL_MS : POLL_WAITING_MS);
    };

    timer = setTimeout(() => void tick(), POLL_WAITING_MS);

    return () => { stopped = true; if (timer) clearTimeout(timer); };
  }, [enabled, fetchState]);

  // Quality beacons, once a minute, best effort.
  useEffect(() => {
    if (call.phase !== 'connected') return undefined;
    const timer = setInterval(() => {
      void reportQuality({ packet_loss_pct: call.quality === 'poor' ? 10 : call.quality === 'fair' ? 4 : 0 }).catch(() => undefined);
    }, 60_000);

    return () => clearInterval(timer);
  }, [call.phase, call.quality, reportQuality]);

  useEffect(() => {
    if (!autoJoin || !roomState.can_join || call.phase !== 'ready') return;
    void join();
  }, [autoJoin, roomState.can_join, call.phase, join]);

  useEffect(() => () => { stopStream(streamRef.current); void clientRef.current?.disconnect().catch(() => undefined); }, []);

  return useMemo(
    () => ({ roomState, call, preflight, preflightBusy, localStream, remoteStream, frameRef, runPreflightNow, join, leave, dispatch }),
    [roomState, call, preflight, preflightBusy, localStream, remoteStream, frameRef, runPreflightNow, join, leave],
  );
}
