// The doctor's in-call screen — and the whole point of Module K.
//
// It renders the UNTOUCHED `Prescription/Writer` component next to a video rail. The props it hands the writer
// are the ordinary ones, built server-side by the Prescription module's own `WriterPayloadBuilder` from the
// ordinary `visits` row that the ordinary `StartVisit` listener created when the serial was called. There is no
// telemedicine prescription path, no copy of the writer, and not one line changed under
// `resources/js/panel/Pages/Prescription/**` (BRIEF §5.K).
//
// The consultation and the prescription are therefore literally the same screen: the doctor talks in the right
// rail and types in the left pane, and pressing "end call" completes the serial through Serials' own
// CompleteConsultation, exactly as finishing an in-person consultation does.
import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Paper from '@mui/material/Paper';
import Typography from '@mui/material/Typography';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import Writer from '@panel/Pages/Prescription/Writer';
import { VideoRail } from '@panel/Components/Telemedicine/VideoRail';
import { useCall } from '@site/Pages/Telemedicine/core/useCall';
import { useSharedProps } from '@shared/inertia';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type { TelemedicineRoomState, WriterPageProps } from '@shared/types/models';

type Props = PageProps<{
  telemedicine: TelemedicineRoomState;
  can_consult: boolean;
  writer: WriterPageProps | null;
  writer_url: string | null;
  ended: boolean;
}>;

export default function Console({ telemedicine, can_consult, writer }: Props) {
  const { t } = useTranslation();
  const shared = useSharedProps();
  const roomName = telemedicine.room;
  const [now, setNow] = useState(() => Date.now());
  const [starting, setStarting] = useState(false);
  const [ending, setEnding] = useState(false);

  const fetchState = useCallback(async () => (await import('@panel/api/telemedicine')).fetchRoomState(roomName), [roomName]);
  const fetchToken = useCallback(async () => (await import('@panel/api/telemedicine')).requestDoctorToken(roomName), [roomName]);
  const reportLeft = useCallback(async () => (await import('@panel/api/telemedicine')).reportDoctorLeft(roomName), [roomName]);
  const reportQuality = useCallback(async (stats: Record<string, number | undefined>) => (await import('@panel/api/telemedicine')).reportDoctorQuality(roomName, stats), [roomName]);

  const { roomState, call, preflight, preflightBusy, localStream, remoteStream, frameRef, runPreflightNow, join, leave, dispatch } = useCall({
    room: telemedicine,
    fetchState,
    fetchToken,
    reportLeft,
    reportQuality,
    autoJoin: true,
  });

  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(timer);
  }, []);

  // The doctor's devices are checked as soon as the console opens: finding out the microphone is blocked while a
  // patient is already waiting is the failure this module exists to avoid.
  useEffect(() => {
    if (can_consult && call.phase === 'idle' && roomState.status !== 'ended' && roomState.status !== 'cancelled') void runPreflightNow(true);
  }, [can_consult, call.phase, roomState.status, runPreflightNow]);

  const start = (): void => {
    setStarting(true);
    router.post(route('panel.telemedicine.start', { room: roomName }), {}, { onFinish: () => setStarting(false), preserveScroll: true });
  };

  const end = (): void => {
    setEnding(true);
    void leave();
    router.post(route('panel.telemedicine.end', { room: roomName }), { reason: 'completed' }, { onFinish: () => setEnding(false) });
  };

  const toggleRecording = (): void => {
    const next = !call.recording;
    dispatch({ type: 'recording', on: next });
    void import('@panel/api/telemedicine')
      .then(({ setRecording }) => setRecording(roomName, next))
      .catch(() => dispatch({ type: 'recording', on: !next }));
  };

  const rail = (
    <VideoRail
      room={roomState}
      call={call}
      preflight={preflight}
      preflightBusy={preflightBusy}
      localStream={localStream}
      remoteStream={remoteStream}
      frameRef={frameRef}
      now={now}
      canConsult={can_consult}
      starting={starting}
      ending={ending}
      onRunPreflight={(video) => void runPreflightNow(video)}
      onStart={start}
      onJoin={() => void join()}
      onToggleMic={() => dispatch({ type: 'toggle.mic' })}
      onToggleCam={() => dispatch({ type: 'toggle.cam' })}
      onToggleRecording={toggleRecording}
      onEnd={end}
      onLeave={() => void leave()}
    />
  );

  return (
    <Box sx={{ display: 'flex', flexDirection: { xs: 'column', lg: 'row' }, gap: 2, alignItems: 'flex-start' }} data-testid="telemedicine-console">
      {/* pr cancels the writer's own negative right margin so it stops exactly where the rail starts. */}
      <Box sx={{ flexGrow: 1, minWidth: 0, width: '100%', pr: { lg: 3 } }}>
        {writer ? (
          <Writer {...({ ...shared, ...writer } as PageProps<WriterPageProps>)} />
        ) : (
          <Paper variant="outlined" sx={{ p: 3 }} data-testid="writer-placeholder">
            <Typography variant="h6">{t('telemedicine.console.no_visit_title')}</Typography>
            <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>{t('telemedicine.console.no_visit_body')}</Typography>
            {can_consult ? (
              <Button variant="contained" sx={{ mt: 2 }} onClick={start} disabled={starting} data-testid="start-call-empty">
                {t('telemedicine.console.start')}
              </Button>
            ) : (
              <Alert severity="info" sx={{ mt: 2 }}>{t('telemedicine.console.not_your_patient')}</Alert>
            )}
          </Paper>
        )}
      </Box>
      <Box sx={{ width: { xs: '100%', lg: 320 }, flexShrink: 0, position: { lg: 'sticky' }, top: { lg: 16 } }}>{rail}</Box>
    </Box>
  );
}

Console.layout = (page: ReactNode) => <PanelLayout title="telemedicine.console.title">{page}</PanelLayout>;
