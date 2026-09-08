// The patient's screen: a waiting room that becomes a call.
//
// The queue half is NOT a second live-state system (BRIEF §5.E, CONVENTIONS §7.2). It embeds the Queue module's
// own QueueState document on first paint and hands it to `useQueueState()`, which subscribes to the existing
// public queue channel and falls back to the existing 5-second ETag poll — so a patient at home sees the same
// "now serving / N ahead / estimated time" as a patient in the corridor, computed once, on the server.
//
// Everything about the call itself is `useCall`. Axios and the video client arrive through dynamic imports, so
// the first load is this page plus the shared site floor (REALTIME.md §8 budget).
import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { SiteLayout } from '@site/Layouts/SiteLayout';
import { useQueueState } from '@shared/realtime/useQueueState';
import { formatBn } from '@shared/format/number';
import { formatTimeDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import type { PageProps } from '@shared/types/inertia';
import type { QueueState } from '@shared/realtime/types';
import type { TelemedicineRoomState } from '@shared/types/models';
import { CallControls } from './CallControls';
import { PreflightCard } from './PreflightCard';
import { VideoStage } from './VideoStage';
import { durationSeconds } from './core/callMachine';
import { useCall } from './core/useCall';

type Props = PageProps<{
  telemedicine: TelemedicineRoomState;
  queue_state: QueueState | null;
  clinic_phone: string | null;
}>;

export default function Room({ telemedicine, queue_state, clinic_phone }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const room = telemedicine.room;

  // `fetch` rather than the api module: the waiting room must not pull axios into the first load.
  const fetchState = useCallback(async (): Promise<TelemedicineRoomState> => {
    const res = await fetch(`/telemedicine/room/${room}/state`, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
    if (!res.ok) throw new Error('state');
    return (await res.json()) as TelemedicineRoomState;
  }, [room]);

  const fetchToken = useCallback(async () => (await import('@site/api/telemedicine')).requestPatientToken(room), [room]);
  const reportLeft = useCallback(async () => (await import('@site/api/telemedicine')).reportPatientLeft(room), [room]);
  const reportQuality = useCallback(async (stats: Record<string, number | undefined>) => (await import('@site/api/telemedicine')).reportPatientQuality(room, stats), [room]);

  const { roomState, call, preflight, preflightBusy, localStream, remoteStream, frameRef, runPreflightNow, join, leave, dispatch } = useCall({
    room: telemedicine,
    fetchState,
    fetchToken,
    reportLeft,
    reportQuality,
    autoJoin: true,
  });

  const { state: queue } = useQueueState({
    tenantId: roomState.queue?.tenant_public_id ?? '',
    doctorSlug: roomState.queue?.doctor_slug ?? '',
    sessionId: roomState.queue?.session_public_id ?? null,
    initial: queue_state,
    enabled: Boolean(roomState.queue?.tenant_public_id) && Boolean(roomState.queue?.session_public_id),
  });

  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(timer);
  }, []);

  const mine = useMemo(() => queue?.serials.find((s) => s.id === roomState.serial?.public_id) ?? null, [queue, roomState.serial]);
  const ahead = mine?.ahead ?? null;
  const inCall = call.phase === 'connected' || call.phase === 'reconnecting' || call.phase === 'connecting';
  const finished = roomState.status === 'ended' || roomState.status === 'cancelled' || call.phase === 'ended';

  const waitingLabel = roomState.presence.doctor
    ? t('telemedicine.call.connecting')
    : roomState.status === 'open'
      ? t('telemedicine.room.doctor_joining')
      : t('telemedicine.room.doctor_not_yet');

  return (
    <div className="space-y-4">
      <header>
        <h1 className="text-lg font-semibold">{t('telemedicine.room.title')}</h1>
        <p className="text-sm text-slate-600">
          {roomState.doctor.name}
          {roomState.serial ? <> · <span data-testid="serial-code">{roomState.serial.code}</span></> : null}
          {' · '}
          {formatTimeDhaka(roomState.scheduled_at, locale)}
        </p>
      </header>

      {finished ? (
        <section className="rounded-lg border border-slate-200 bg-white p-4" data-testid="call-finished">
          <h2 className="text-sm font-semibold">{t('telemedicine.room.finished_title')}</h2>
          <p className="mt-1 text-sm text-slate-600">{t('telemedicine.room.finished_body')}</p>
        </section>
      ) : (
        <>
          {inCall || call.phase === 'ready' ? (
            <section className="space-y-3" data-testid="call-stage">
              <VideoStage
                local={localStream}
                remote={remoteStream}
                remotePresent={roomState.presence.doctor}
                camEnabled={call.camEnabled}
                frameRef={frameRef}
                waitingLabel={waitingLabel}
              />
              {inCall ? (
                <>
                  <CallControls
                    state={call}
                    hasCamera={preflight.hasCamera}
                    onToggleMic={() => dispatch({ type: 'toggle.mic' })}
                    onToggleCam={() => dispatch({ type: 'toggle.cam' })}
                    onLeave={() => void leave()}
                    leaveLabel={t('telemedicine.call.leave')}
                  />
                  <p className="text-xs text-slate-500" data-testid="call-duration">
                    {t('telemedicine.call.duration', { seconds: formatBn(durationSeconds(call, now), locale) })}
                  </p>
                </>
              ) : (
                <button
                  type="button"
                  onClick={() => void join()}
                  disabled={!roomState.can_join}
                  data-testid="join-call"
                  className="w-full rounded bg-primary px-4 py-3 text-base font-semibold text-on-primary disabled:opacity-60"
                >
                  {roomState.can_join ? t('telemedicine.room.join') : t('telemedicine.room.doctor_not_yet')}
                </button>
              )}
              {call.errorKey ? <p className="text-sm text-red-700" data-testid="call-error">{t(call.errorKey)}</p> : null}
            </section>
          ) : (
            <PreflightCard report={preflight} busy={preflightBusy} onRun={() => void runPreflightNow(true)} onRunAudioOnly={() => void runPreflightNow(false)} />
          )}

          <section className="rounded-lg border border-slate-200 bg-white p-4" data-testid="waiting-queue">
            <h2 className="text-sm font-semibold">{t('telemedicine.room.queue_title')}</h2>
            {queue ? (
              <dl className="mt-2 grid grid-cols-2 gap-3 text-sm">
                <div>
                  <dt className="text-slate-500">{t('telemedicine.room.now_serving')}</dt>
                  <dd className="text-lg font-semibold" data-testid="now-serving">{queue.now_serving?.c ?? '—'}</dd>
                </div>
                <div>
                  <dt className="text-slate-500">{t('telemedicine.room.ahead')}</dt>
                  <dd className="text-lg font-semibold" data-testid="ahead">{ahead === null ? '—' : formatBn(ahead, locale)}</dd>
                </div>
              </dl>
            ) : (
              <p className="mt-1 text-sm text-slate-600">{t('telemedicine.room.queue_unavailable')}</p>
            )}
          </section>
        </>
      )}

      {clinic_phone ? (
        <p className="text-xs text-slate-500">{t('telemedicine.room.help', { phone: clinic_phone })}</p>
      ) : null}
    </div>
  );
}

Room.layout = (page: ReactNode) => <SiteLayout title="telemedicine.room.title">{page}</SiteLayout>;
