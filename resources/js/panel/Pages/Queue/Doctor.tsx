// "Today's session" — the doctor's session page (Inertia::render('Queue/Doctor'), REALTIME.md §11, BRIEF §5.E/§5.G):
// the session header with its counts, one big Call next, the patient card of the serial in the chamber with its
// Prescribe action (the flagship flow: call → prescribe → issue completes the consultation), the next three, the
// running ETA, a one-tap delay broadcast — and the whole roster in serial-number order, each row with the action
// its state allows (call this patient / start / prescribe / view / print). Live over the private doctor channel
// (`call.next`) plus the session's public `queue.state`; the roster is re-read whenever that state's version moves
// (a check-in at the desk, vitals recorded, a completion) — event-driven, never a poller of its own. Every
// mutation is a Serials endpoint, except Prescribe, which opens the serial's visit through the Prescription module
// and lands in its writer.
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import Grid from '@mui/material/Grid';
import Paper from '@mui/material/Paper';
import Snackbar from '@mui/material/Snackbar';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import Typography from '@mui/material/Typography';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { SessionHeader } from '@panel/Components/Queue/SessionHeader';
import { SessionRoster } from '@panel/Components/Queue/SessionRoster';
import { callNext, delaySession, serialAction } from '@panel/api/serials';
import { startVisit } from '@panel/api/prescription';
import { fetchRoster } from '@panel/api/queue';
import { useTodaySessionBadge } from '@panel/hooks/queue/useTodaySessionBadge';
import { useChannel } from '@shared/realtime/useChannel';
import { useQueueState } from '@shared/realtime/useQueueState';
import { QUEUE_EVENTS, type QueueState } from '@shared/realtime/types';
import { isApiError } from '@shared/http';
import { formatBn } from '@shared/format/number';
import { formatTimeDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type { QueueDoctor, QueueSessionRow, SerialStatus } from '@shared/types/models';

export interface DoctorSessionSummary {
  public_id: string;
  code: string;
  status: string;
  planned_start_at: string;
  planned_end_at: string;
  delay_minutes: number;
}

type Props = PageProps<{
  tenant_public_id: string | null;
  doctor_channel: string | null;
  doctor: QueueDoctor;
  date: string;
  session_id: string | null;
  state: QueueState | null;
  sessions: DoctorSessionSummary[];
  roster: Record<string, QueueSessionRow>;
  notice: 'no_one_waiting' | null;
  can: { call_next: boolean; delay: boolean; prescribe: boolean };
}>;

const DELAY_PRESETS = [15, 30, 45];

const LIVE_STATUS: Record<'b' | 'c' | 'i', SerialStatus> = { b: 'booked', c: 'checked_in', i: 'in_consultation' };

export default function Doctor({ tenant_public_id, doctor_channel, doctor, session_id, state: initial, sessions, roster: initialRoster, notice, can }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [sessionId, setSessionId] = useState<string | null>(session_id);
  const [roster, setRoster] = useState<Record<string, QueueSessionRow>>(initialRoster);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [toast, setToast] = useState<string | null>(notice === 'no_one_waiting' ? t('queue.doctor.no_one_waiting') : null);
  const [delayOpen, setDelayOpen] = useState(false);
  const [delayMinutes, setDelayMinutes] = useState(15);
  const [delayMessage, setDelayMessage] = useState('');
  const [tick, setTick] = useState(0);

  const { state, mode } = useQueueState({
    tenantId: tenant_public_id ?? '',
    doctorSlug: doctor.slug,
    sessionId,
    initial,
    enabled: Boolean(tenant_public_id) && sessionId !== null,
  });

  useChannel(doctor_channel, {
    [QUEUE_EVENTS.callNext]: () => setTick((n) => n + 1),
    [QUEUE_EVENTS.serialCalled]: () => setTick((n) => n + 1),
  }, { enabled: mode === 'online' && Boolean(doctor_channel) });

  // the ETA of the serial in the chamber keeps moving between events
  useEffect(() => {
    const id = window.setInterval(() => setTick((n) => n + 1), 30_000);
    return () => window.clearInterval(id);
  }, []);

  // The sidebar's "Today's session" count follows the live state while this page is open (useTodaySessionBadge).
  useEffect(() => {
    if (state) useTodaySessionBadge.getState().set(state.session.id, state.counts.checked_in);
  }, [state]);

  // The roster is re-read when the queue state's version moves (or the doctor switches session) — the same
  // signal the desk board refreshes on, one GET per change, never a timer. The first render already has it.
  const rosterVersion = useRef<number | null>(initial?.version ?? null);
  const rosterSession = useRef<string | null>(session_id);

  // One reader for both paths; it remembers the session version the rows reflect, so the live effect below does
  // not re-fetch what a row action has already brought back.
  const reloadRoster = useCallback(async (): Promise<void> => {
    if (sessionId === null) return;
    const r = await fetchRoster(sessionId, doctor.slug);
    rosterVersion.current = r.version ?? rosterVersion.current;
    setRoster(r.roster);
  }, [sessionId, doctor.slug]);

  useEffect(() => {
    if (sessionId === null) return undefined;
    const version = state?.version ?? null;
    const switched = rosterSession.current !== sessionId;
    if (!switched && (version === null || (rosterVersion.current !== null && version <= rosterVersion.current))) return undefined;
    rosterSession.current = sessionId;
    rosterVersion.current = version;
    let cancelled = false;
    fetchRoster(sessionId, doctor.slug).then((r) => { if (!cancelled) setRoster(r.roster); }).catch(() => undefined);
    return () => { cancelled = true; };
  }, [state?.version, sessionId, doctor.slug]);

  const serving = useMemo(() => state?.serials.find((s) => s.s === 'i') ?? null, [state]);
  const upNext = useMemo(() => (state?.serials ?? []).filter((s) => s.s === 'c').slice(0, 3), [state]);
  const waiting = state?.counts.waiting ?? 0;
  const bookedNotArrived = state?.counts.booked ?? 0;

  // Rows in serial-number order; the live state's status wins for the serials it lists.
  const rows = useMemo(() => {
    const live = new Map<string, SerialStatus>();
    for (const s of state?.serials ?? []) live.set(s.id, LIVE_STATUS[s.s]);
    return Object.values(roster).map((row) => ({ ...row, status: live.get(row.serial_id) ?? row.status })).sort((a, b) => a.number - b.number);
  }, [roster, state]);

  const run = useCallback(async (fn: () => Promise<unknown>, done?: string): Promise<void> => {
    setBusy(true);
    setError(null);
    try {
      await fn();
      if (done) setToast(done);
    } catch (e) {
      setError(isApiError(e) ? t(`serials.errors.${e.code ?? 'unknown'}`, { defaultValue: e.message }) : e instanceof Error ? e.message : String(e));
    } finally {
      setBusy(false);
    }
  }, [t]);

  const doCallNext = useCallback((): void => {
    if (!sessionId || !can.call_next) return;
    void run(async () => {
      const r = await callNext(sessionId);
      if (r.called) setToast(t('queue.doctor.called', { code: formatBn(r.called.display_code, locale) }));
      else setToast(t('queue.doctor.no_one_waiting'));
    });
  }, [sessionId, can.call_next, run, t, locale]);

  const onServing = useCallback((action: 'start' | 'complete' | 'skip' | 'return' | 'no-show'): void => {
    if (!serving) return;
    void run(() => serialAction(serving.id, action));
  }, [serving, run]);

  // The serial in the chamber → its visit (opened idempotently; the SerialCalled listener normally already has)
  // → the writer. Exactly what Prescription/Show and the telemedicine console do with `writer_url`.
  const canPrescribe = serving !== null && can.prescribe;
  const prescribe = useCallback((): void => {
    if (!serving || !can.prescribe) return;
    void run(async () => {
      const { writer_url } = await startVisit(serving.id);
      router.visit(writer_url);
    });
  }, [serving, can.prescribe, run]);

  // Per-row: call THIS patient (CallSerial — the "manage my patients" primitive), start, or open their writer.
  // Both reload the roster themselves rather than waiting for a queue-state frame: `call` bumps the session
  // version and would arrive eventually, but `start` only stamps consultation_started_at — no status change, no
  // version bump (SERIAL_ENGINE §6.4) — so nothing would ever tell the row it has started.
  const callRow = useCallback((row: QueueSessionRow): void => {
    if (!can.call_next) return;
    void run(async () => {
      await serialAction(row.serial_id, 'call');
      await reloadRoster();
    }, t('queue.doctor.called', { code: formatBn(row.code, locale) }));
  }, [can.call_next, run, reloadRoster, t, locale]);
  const startRow = useCallback((row: QueueSessionRow): void => {
    void run(async () => {
      await serialAction(row.serial_id, 'start');
      await reloadRoster();
    });
  }, [run, reloadRoster]);
  const prescribeRow = useCallback((row: QueueSessionRow): void => {
    if (!can.prescribe) return;
    if (row.visit_id !== null && row.status !== 'in_consultation') {
      router.visit(route('panel.prescription.writer', { visit: row.visit_id }));
      return;
    }
    void run(async () => {
      const { writer_url } = await startVisit(row.serial_id);
      router.visit(writer_url);
    });
  }, [can.prescribe, run]);

  useEffect(() => {
    const onKey = (e: KeyboardEvent): void => {
      const target = e.target as HTMLElement | null;
      if (target && ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)) return;
      if (e.code === 'Space') { e.preventDefault(); doCallNext(); }
      else if (e.key === 'Enter') { e.preventDefault(); onServing('complete'); }
      else if (e.key.toLowerCase() === 'n') { e.preventDefault(); onServing('no-show'); }
      else if (e.key.toLowerCase() === 'p') { e.preventDefault(); prescribe(); }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [doCallNext, onServing, prescribe]);

  const submitDelay = (minutes: number): void => {
    if (!sessionId) return;
    void run(async () => {
      await delaySession(sessionId, minutes, delayMessage.trim() === '' ? null : delayMessage.trim());
      setDelayOpen(false);
      setDelayMessage('');
    }, t('queue.doctor.delay'));
  };

  const card = serving ? roster[serving.id]?.patient ?? null : null;
  const avgMinutes = Math.max(1, Math.round((state?.avg_consult_seconds ?? 300) / 60));
  const current = sessions.find((s) => s.public_id === sessionId);

  return (
    <Stack spacing={2} data-tick={tick}>
      <Stack direction={{ xs: 'column', md: 'row' }} spacing={1} sx={{ alignItems: { md: 'center' } }}>
        <Typography variant="h5" component="h1" sx={{ flexGrow: 1 }}>
          {locale === 'bn' && doctor.name_bn ? doctor.name_bn : doctor.name}
          {state ? ` · ${t('queue.doctor.session', { code: formatBn(state.session.code, locale) })}` : ''}
        </Typography>
        <Chip size="small" color={mode === 'online' ? 'success' : mode === 'degraded' ? 'warning' : 'default'} label={mode} />
        {can.delay ? <Button variant="outlined" onClick={() => setDelayOpen(true)} disabled={busy || !sessionId}>{t('queue.doctor.delay')}</Button> : null}
      </Stack>

      {error ? <Alert severity="error" onClose={() => setError(null)}>{error}</Alert> : null}
      {state === null ? <Alert severity="info">{t('queue.doctor.no_session')}</Alert> : null}
      {state && state.session.delay_minutes > 0 ? (
        <Alert severity="warning">{t('queue.page.delay_banner', { minutes: formatBn(state.session.delay_minutes, locale) })}</Alert>
      ) : null}

      {state ? <SessionHeader state={state} plannedEndAt={current?.planned_end_at ?? null} locale={locale} /> : null}

      <Grid container spacing={2}>
        <Grid size={{ xs: 12, md: 7 }}>
          <Paper variant="outlined" sx={{ p: 3, textAlign: 'center' }}>
            <Typography variant="overline" color="text.secondary">{t('queue.doctor.now_serving')}</Typography>
            <Typography sx={{ fontSize: 72, fontWeight: 900, lineHeight: 1.05, letterSpacing: '0.04em' }} data-testid="now-serving">
              {serving ? formatBn(serving.c, locale) : '—'}
            </Typography>
            {card ? (
              <Typography variant="body1" data-testid="now-serving-card">
                {card.name}
                {card.sex ? ` · ${t(`patients.gender.${card.sex}`)}` : ''}
                {card.age_text ? ` · ${formatBn(card.age_text, locale)}` : ''}
                {card.patient_code ? ` · ${formatBn(card.patient_code, locale)}` : ''}
              </Typography>
            ) : null}
            {canPrescribe ? (
              <Button size="large" variant="contained" color="success" onClick={prescribe} disabled={busy} sx={{ mt: 2 }} data-testid="prescribe">
                {t('queue.doctor.prescribe')}
              </Button>
            ) : null}
            <Stack direction="row" spacing={1} sx={{ mt: 2, flexWrap: 'wrap', gap: 1, justifyContent: 'center' }}>
              <Button size="large" variant={serving ? 'outlined' : 'contained'} onClick={doCallNext} disabled={busy || !can.call_next || !sessionId} data-testid="call-next">{t('queue.doctor.call_next')}</Button>
              <Button onClick={() => onServing('start')} disabled={busy || !serving}>{t('queue.doctor.start')}</Button>
              <Button onClick={() => onServing('complete')} disabled={busy || !serving}>{t('queue.doctor.complete')}</Button>
              <Button onClick={() => onServing('skip')} disabled={busy || !serving}>{t('queue.doctor.skip')}</Button>
              <Button onClick={() => onServing('return')} disabled={busy || !serving}>{t('queue.doctor.return')}</Button>
            </Stack>
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 2 }}>{t('queue.doctor.shortcuts')}</Typography>
          </Paper>
        </Grid>

        <Grid size={{ xs: 12, md: 5 }}>
          <Paper variant="outlined" sx={{ p: 2 }}>
            <Typography variant="subtitle1">{t('queue.doctor.next_up')}</Typography>
            {upNext.length === 0 ? (
              <Typography color="text.secondary" sx={{ mt: 1 }}>{t('queue.doctor.no_one_waiting')}</Typography>
            ) : (
              <Stack spacing={1} sx={{ mt: 1 }}>
                {upNext.map((s) => (
                  <Box key={s.id} sx={{ display: 'flex', justifyContent: 'space-between', gap: 1 }}>
                    <Typography sx={{ fontWeight: 700 }}>{formatBn(s.c, locale)}</Typography>
                    <Typography color="text.secondary">{roster[s.id]?.patient?.name ?? '—'}</Typography>
                    <Typography color="text.secondary">{s.eta ? formatTimeDhaka(s.eta, locale) : '—'}</Typography>
                  </Box>
                ))}
              </Stack>
            )}
            <Box sx={{ mt: 2, color: 'text.secondary' }}>
              <Typography variant="body2">{t('queue.page.waiting')}: {formatBn(waiting, locale)}</Typography>
              <Typography variant="body2">{t('queue.doctor.waiting_booked', { count: formatBn(bookedNotArrived, locale) })}</Typography>
              <Typography variant="body2">{t('queue.doctor.avg_consult', { minutes: formatBn(avgMinutes, locale) })}</Typography>
              {state?.eta_confidence === 'low' ? <Typography variant="caption">{t('queue.doctor.confidence_low')}</Typography> : null}
            </Box>
          </Paper>

          {sessions.length > 1 ? (
            <Paper variant="outlined" sx={{ p: 2, mt: 2 }}>
              <ToggleButtonGroup exclusive size="small" value={sessionId} onChange={(_, v: string | null) => v && setSessionId(v)}>
                {sessions.map((s) => <ToggleButton key={s.public_id} value={s.public_id}>{formatBn(s.code, locale)}</ToggleButton>)}
              </ToggleButtonGroup>
            </Paper>
          ) : null}
        </Grid>
      </Grid>

      {state ? (
        <SessionRoster rows={rows} servingId={serving?.id ?? null} busy={busy} can={{ call_next: can.call_next, prescribe: can.prescribe }} locale={locale} onCall={callRow} onStart={startRow} onPrescribe={prescribeRow} />
      ) : null}

      <Dialog open={delayOpen} onClose={() => setDelayOpen(false)} fullWidth maxWidth="xs">
        <DialogTitle>{t('queue.doctor.delay_title')}</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            <ToggleButtonGroup exclusive value={delayMinutes} onChange={(_, v: number | null) => v !== null && setDelayMinutes(v)}>
              {DELAY_PRESETS.map((m) => <ToggleButton key={m} value={m}>{formatBn(m, locale)}</ToggleButton>)}
            </ToggleButtonGroup>
            <TextField label={t('queue.doctor.delay_custom')} type="number" value={delayMinutes} onChange={(e) => setDelayMinutes(Math.max(0, Number(e.target.value)))} />
            <TextField label={t('queue.doctor.delay_message')} value={delayMessage} onChange={(e) => setDelayMessage(e.target.value)} />
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => submitDelay(0)} disabled={busy}>{t('queue.doctor.clear_delay')}</Button>
          <Button onClick={() => setDelayOpen(false)}>{t('common.actions.cancel')}</Button>
          <Button variant="contained" onClick={() => submitDelay(delayMinutes)} disabled={busy}>{t('common.actions.save')}</Button>
        </DialogActions>
      </Dialog>

      <Snackbar open={toast !== null} autoHideDuration={4000} onClose={() => setToast(null)} message={toast} />
    </Stack>
  );
}

Doctor.layout = (page: ReactNode) => <PanelLayout title="queue.doctor.page_title">{page}</PanelLayout>;
