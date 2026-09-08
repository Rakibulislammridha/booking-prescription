// The doctor's in-call rail: the patient's video, the controls, connection quality, and the one button that
// ends the call and completes the serial together.
//
// The widgets are MUI siblings of the patient's Tailwind ones (the panel has no Tailwind), but the BEHAVIOUR is
// imported from the site module: `callMachine`, `preflight` and `useCall` in `@site/Pages/Telemedicine/core/**`
// carry no styling, resolve in both builds, and are covered by one Vitest suite — which is how a doctor and a
// patient stay in agreement about what "reconnecting" or "poor connection" means. Nothing MUI-shaped travels the
// other way, so the site's dependency budget is untouched.
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import Paper from '@mui/material/Paper';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { CallControlBar } from './CallControlBar';
import { PreflightPanel } from './PreflightPanel';
import { VideoPane } from './VideoPane';
import { durationSeconds, type CallState } from '@site/Pages/Telemedicine/core/callMachine';
import type { PreflightReport } from '@site/Pages/Telemedicine/core/preflight';
import type { TelemedicineRoomState } from '@shared/types/models';

export interface VideoRailProps {
  room: TelemedicineRoomState;
  call: CallState;
  preflight: PreflightReport;
  preflightBusy: boolean;
  localStream: MediaStream | null;
  remoteStream: MediaStream | null;
  frameRef: (node: HTMLDivElement | null) => void;
  now: number;
  canConsult: boolean;
  starting: boolean;
  ending: boolean;
  onRunPreflight(video: boolean): void;
  onStart(): void;
  onJoin(): void;
  onToggleMic(): void;
  onToggleCam(): void;
  onToggleRecording(): void;
  onEnd(): void;
  onLeave(): void;
}

export function VideoRail(props: VideoRailProps) {
  const { t } = useTranslation();
  const { room, call, preflight, localStream, remoteStream, frameRef, now, canConsult } = props;
  const live = call.phase === 'connected' || call.phase === 'reconnecting';
  const waitingLabel = room.presence.patient ? t('telemedicine.call.connecting') : t('telemedicine.console.patient_not_yet');

  const status = useMemo(() => {
    if (room.status === 'ended') return { key: 'telemedicine.console.status.ended', color: 'default' as const };
    if (room.status === 'cancelled') return { key: 'telemedicine.console.status.cancelled', color: 'default' as const };
    if (live) return { key: 'telemedicine.console.status.live', color: 'success' as const };
    if (room.status === 'open') return { key: 'telemedicine.console.status.open', color: 'info' as const };
    return { key: 'telemedicine.console.status.scheduled', color: 'default' as const };
  }, [room.status, live]);

  return (
    <Paper variant="outlined" sx={{ p: 1.5, display: 'flex', flexDirection: 'column', gap: 1.5 }} data-testid="video-rail">
      <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
        <Typography variant="subtitle2" sx={{ fontWeight: 700 }}>{t('telemedicine.console.title')}</Typography>
        <Chip size="small" color={status.color} label={t(status.key)} data-testid="room-status" />
        {live ? <Chip size="small" variant="outlined" label={t('telemedicine.call.duration', { seconds: durationSeconds(call, now) })} sx={{ ml: 'auto' }} /> : null}
      </Stack>

      {room.status === 'ended' || room.status === 'cancelled' ? (
        <Typography variant="body2" color="text.secondary">{t('telemedicine.console.ended_body')}</Typography>
      ) : call.phase === 'idle' || call.phase === 'preflight' || (call.phase === 'failed' && !live) ? (
        <PreflightPanel report={preflight} busy={props.preflightBusy} onRun={props.onRunPreflight} />
      ) : (
        <>
          <VideoPane
            local={localStream}
            remote={remoteStream}
            camEnabled={call.camEnabled}
            frameRef={frameRef}
            waitingLabel={waitingLabel}
          />
          {live ? (
            <CallControlBar
              state={call}
              hasCamera={preflight.hasCamera}
              onToggleMic={props.onToggleMic}
              onToggleCam={props.onToggleCam}
              onLeave={props.onLeave}
            />
          ) : null}
        </>
      )}

      {call.errorKey ? <Typography variant="caption" color="error" data-testid="rail-error">{t(call.errorKey)}</Typography> : null}

      <Stack spacing={1}>
        {room.status === 'scheduled' && canConsult ? (
          <Button variant="contained" onClick={props.onStart} disabled={props.starting || !preflight.canJoin} data-testid="start-call">
            {t('telemedicine.console.start')}
          </Button>
        ) : null}
        {room.status === 'open' && !live && canConsult && call.phase === 'ready' ? (
          <Button variant="contained" onClick={props.onJoin} data-testid="rejoin-call">{t('telemedicine.console.rejoin')}</Button>
        ) : null}
        {room.recording.allowed && live ? (
          <Button size="small" variant="outlined" color={call.recording ? 'error' : 'inherit'} onClick={props.onToggleRecording} data-testid="toggle-recording">
            {call.recording ? t('telemedicine.console.recording_stop') : t('telemedicine.console.recording_start')}
          </Button>
        ) : null}
        {room.status === 'open' && canConsult ? (
          <Button variant="contained" color="error" onClick={props.onEnd} disabled={props.ending} data-testid="end-call">
            {t('telemedicine.console.end_and_complete')}
          </Button>
        ) : null}
      </Stack>

      <Typography variant="caption" color="text.secondary">
        {room.presence.patient ? t('telemedicine.console.patient_present') : t('telemedicine.console.patient_absent')}
      </Typography>
    </Paper>
  );
}
