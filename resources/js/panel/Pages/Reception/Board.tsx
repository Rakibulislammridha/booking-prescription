// Today's board (Inertia::render('Reception/Board'), BRIEF §5.F): every doctor/session of the active branch with
// booked / arrived / done / remaining, one-click booking, check-in, collect fee, print slip, call-next, cancel with a
// reason code, patient quick-search, kiosk QR, device registration, conflict cards. Live via the reception channel
// when online; polled every 5 s when degraded; served from Dexie + the event log when offline (OFFLINE §5–§8).
import { lazy, Suspense, useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Stack from '@mui/material/Stack';
import Button from '@mui/material/Button';
import Typography from '@mui/material/Typography';
import Alert from '@mui/material/Alert';
import Chip from '@mui/material/Chip';
import Paper from '@mui/material/Paper';
import Snackbar from '@mui/material/Snackbar';
import Box from '@mui/material/Box';
import Grid from '@mui/material/Grid';
import DevicesIcon from '@mui/icons-material/TabletMac';
import ReportIcon from '@mui/icons-material/Summarize';
import RefreshIcon from '@mui/icons-material/Refresh';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { SessionTile, type PrescriptionOutput } from '@panel/Components/Reception/SessionTile';
import { PatientQuickSearch, type QuickSearchHit } from '@panel/Components/Reception/PatientQuickSearch';
import type { SlipData, SlipLabels } from '@panel/Components/Reception/TokenSlip';

// Everything below is a dialog, a print sheet or a side panel: none of it is on screen at first paint, and the
// desk runs on cheap clinic hardware over clinic wifi (BRIEF §8). Splitting them keeps the board's first load
// inside scripts/check-panel-budget.sh. The service worker precaches every built chunk, so an offline desk still
// opens them (OFFLINE.md §11) — they are lazy, not optional.
const BookingDialog = lazy(() => import('@panel/Components/Reception/BookingDialog').then((m) => ({ default: m.BookingDialog })));
const PatientDuesPanel = lazy(() => import('@panel/Components/Billing/PatientDuesPanel').then((m) => ({ default: m.PatientDuesPanel })));
const ConflictCards = lazy(() => import('@panel/Components/Reception/ConflictCards').then((m) => ({ default: m.ConflictCards })));
const TokenSlip = lazy(() => import('@panel/Components/Reception/TokenSlip').then((m) => ({ default: m.TokenSlip })));
const CancelDialog = lazy(() => import('@panel/Components/Reception/DeskDialogs').then((m) => ({ default: m.CancelDialog })));
const CollectFeeDialog = lazy(() => import('@panel/Components/Reception/DeskDialogs').then((m) => ({ default: m.CollectFeeDialog })));
const DeviceRegistrationDialog = lazy(() => import('@panel/Components/Reception/DeskDialogs').then((m) => ({ default: m.DeviceRegistrationDialog })));
const KioskQrDialog = lazy(() => import('@panel/Components/Reception/DeskDialogs').then((m) => ({ default: m.KioskQrDialog })));
import { useDesk, deviceFingerprint, APP_VERSION } from '@panel/hooks/reception/useDesk';
import { callNext, serialAction } from '@panel/api/serials';
import { cancelBooking, collectFee, fetchKioskUrl, fetchPrintTemplates, registerDevice } from '@panel/api/reception';
import { useSharedProps } from '@shared/inertia';
import { isApiError } from '@shared/http';
import { route } from '@shared/routes';
import { getLocale } from '@shared/locale';
import { formatBn } from '@shared/format/number';
import { useConflicts, type PrintTemplate } from '@shared/offline';
import { BlockIssuer } from '@shared/offline';
import type { PageProps } from '@shared/types/inertia';
import type { Board as BoardDoc, BoardSession, DeskSerial } from '@shared/types/models';

type Props = PageProps<{
  board: BoardDoc;
  tenant_public_id: string | null;
  channel: string | null;
  print_format: PrintTemplate['id'];
  settings: Record<string, unknown>;
  can: { issue: boolean; call_next: boolean; cancel: boolean; collect: boolean; register_device: boolean; record_vitals: boolean; search_patients: boolean; print_prescription: boolean; check_in: boolean; kiosk: boolean };
  actor_public_id: string | null;
  /** DoctorScope's own answer for this viewer (BoardController::index): the board they were handed is narrowed. */
  doctor_scoped: boolean;
}>;

export default function Board({ board: initial, tenant_public_id, channel, print_format, settings, can, actor_public_id, doctor_scoped }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const shared = useSharedProps();
  const desk = useDesk(initial, { tenantPublicId: tenant_public_id, channel, actorPublicId: actor_public_id, doctorScoped: doctor_scoped, settings });
  const conflicts = useConflicts();
  const [booking, setBooking] = useState<{ session: BoardSession; channel: 'counter' | 'walkin' } | null>(null);
  const [collect, setCollect] = useState<{ session: BoardSession; serial: DeskSerial } | null>(null);
  const [cancel, setCancel] = useState<{ session: BoardSession; serial: DeskSerial } | null>(null);
  const [registering, setRegistering] = useState(false);
  const [duesPatient, setDuesPatient] = useState<string | null>(null);
  const [kiosk, setKiosk] = useState<{ open: boolean; url: string | null; hours: number }>({ open: false, url: null, hours: 12 });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [toast, setToast] = useState<string | null>(null);
  const [staffTemplates, setStaffTemplates] = useState<PrintTemplate[]>([]);
  const [slip, setSlip] = useState<{ data: SlipData; key: number }>({ data: { serialPublicId: '', displayCode: '', doctorName: '', doctorNameBn: null, sessionCode: '', sessionLabel: '', date: '', patientName: '', doctorSlug: '', origin: '', offline: false }, key: 0 });
  const isAdmin = shared.auth.user?.roles.includes('hospital_admin') ?? false;
  // A compounder works this same board, narrowed server-side to the doctors assigned to them (DoctorScope). When
  // the board is short, the reason belongs on the screen — otherwise an absent colleague reads as a cancelled
  // session. The server says whether this viewer is narrowed; the page used to mirror DoctorScope's two clauses
  // here ("has the compounder role and is not a hospital admin"), which was a copy of a boundary rule living in
  // the client — and the same boolean now decides whether the desk may touch the device cache at all (useDesk),
  // which is nothing a screen should be guessing at.
  const isScoped = doctor_scoped;
  // The names are taken from the board itself, which is already the filtered document — so this can only name the
  // assigned doctors who HAVE a session here today. There is no `scope` prop to name the rest; when none of them
  // does, the banner says that rather than naming nobody.
  const scopeDoctors = useMemo(
    () => [...new Map(desk.board.sessions.map((s) => [s.doctor.public_id, locale === 'bn' && s.doctor.name_bn ? s.doctor.name_bn : s.doctor.name])).values()],
    [desk.board.sessions, locale],
  );
  // Billing's own panel, mounted here rather than re-derived: the desk asks "does this patient owe anything?"
  // before it takes the next payment. It needs the server, so it is hidden while the desk is offline.
  const canSeeDues = shared.auth.user?.permissions.includes('billing.invoices.view') ?? false;
  const offline = desk.mode === 'offline';
  // The right-hand column is the patient search and this device's leased blocks. A desk that has neither — the
  // compounder, who may not look patients up and whose viewer never leases a block — gets the board full width
  // instead of two thirds of the screen and a column of nothing.
  const activeBlocks = desk.blocks.filter((b) => b.status === 'active');
  const sidebar = can.search_patients || activeBlocks.length > 0;
  const templates = desk.templates.length > 0 ? desk.templates : staffTemplates;
  const template = templates.find((x) => x.id === print_format) ?? templates[0] ?? null;
  const labels = useMemo<SlipLabels>(() => ({ ahead: t('reception.slip.ahead'), eta: t('reception.slip.eta'), fee: t('reception.slip.fee'), paid: t('reception.payment.paid'), due: t('reception.payment.unpaid'), receipt: t('reception.slip.receipt'), offline: t('reception.slip.offline'), footer: t('reception.slip.footer') }), [t]);

  useEffect(() => { if (!desk.registered) fetchPrintTemplates().then((r) => setStaffTemplates(r.templates)).catch(() => undefined); }, [desk.registered]);
  useEffect(() => { for (const s of desk.board.sessions) void desk.ensureBlocks(s); }, [desk.board.sessions.length, desk.registered, desk.mode]); // eslint-disable-line react-hooks/exhaustive-deps

  const run = useCallback(async (fn: () => Promise<unknown>, done?: string): Promise<boolean> => {
    setBusy(true);
    setError(null);
    try {
      await fn();
      if (done) setToast(done);
      return true;
    } catch (e) {
      setError(isApiError(e) ? t(`serials.errors.${e.code ?? 'unknown'}`, { defaultValue: e.message }) : e instanceof Error ? e.message : String(e));
      return false;
    } finally {
      setBusy(false);
    }
  }, [t]);

  const blockRemaining = (session: BoardSession): number => BlockIssuer.remaining(desk.blocks.filter((b) => b.sessionId === session.public_id && b.status === 'active'));

  const print = useCallback((session: BoardSession, serial: DeskSerial): void => {
    setSlip((s) => ({ key: s.key + 1, data: {
      serialPublicId: serial.public_id, displayCode: serial.display_code, doctorName: session.doctor.name, doctorNameBn: session.doctor.name_bn, sessionCode: session.code,
      sessionLabel: t('reception.board.session', { code: session.code }), date: session.date, plannedStartAt: session.expected_start_at, patientName: serial.patient?.name ?? '',
      ahead: serial.status === 'booked' || serial.status === 'checked_in' ? Math.max(0, session.serials.filter((x) => (x.status === 'booked' || x.status === 'checked_in') && x.position < serial.position).length) : null,
      eta: serial.eta, feePaisa: serial.appointment?.fee_paisa ?? null, paymentStatus: serial.appointment?.payment_status ?? null, receiptNo: null, doctorSlug: session.doctor.slug,
      origin: window.location.origin, offline: serial.source === 'offline' || serial.public_id.startsWith('local:'),
    } }));
    void desk.printed(serial.public_id, print_format);
  }, [desk, print_format, t]);

  const checkIn = (session: BoardSession, serial: DeskSerial): void => {
    void run(async () => {
      if (offline || serial.public_id.startsWith('local:')) await desk.checkInOffline(serial.public_id);
      else { await serialAction(serial.public_id, 'check-in'); await desk.refresh(); }
    }, t('reception.toast.checked_in', { code: formatBn(serial.display_code, locale) }));
  };

  const doCollect = (amountPaisa: number, note: string | null): void => {
    if (!collect) return;
    void run(async () => {
      if (offline || collect.serial.public_id.startsWith('local:')) await desk.collectCashOffline(collect.serial.public_id, amountPaisa, note ?? undefined);
      else { await collectFee(collect.serial.appointment?.public_id ?? '', amountPaisa, note); await desk.refresh(); }
      setCollect(null);
    }, t('reception.toast.collected'));
  };

  // The cancel response says whether the patient is owed their fee back (SERIAL_ENGINE §6: inside the cutoff, or
  // any clinic-side reason). The receptionist is the person who hands the money over, so they are told here
  // instead of the flag being dropped on the floor.
  const doCancel = (reason: string, note: string | null): void => {
    if (!cancel?.serial.appointment) return;
    const collected = cancel.serial.appointment.payment_status === 'paid' || cancel.serial.appointment.payment_status === 'partial';
    void run(async () => {
      const { refund_eligible } = await cancelBooking(cancel.serial.appointment?.public_id ?? '', reason, note);
      await desk.refresh();
      setCancel(null);
      setToast(refund_eligible
        ? t(collected ? 'reception.toast.cancelled_refund_due' : 'reception.toast.cancelled_refundable')
        : t('reception.toast.cancelled_no_refund'));
    });
  };

  // BRIEF §5.G.2: the compounder records vitals before the doctor sees the patient. One click from the row opens
  // (idempotently) the visit and lands on the desk's vitals screen — the doctor's writer is never reachable here.
  const openVitals = (serial: DeskSerial): void => {
    router.post(route('panel.reception.vitals.open', { serial: serial.public_id }));
  };

  // BRIEF §5.G.4: the prescription is printed at the desk too. The row carries only the issued version's handle;
  // the sheet itself is the existing output route (view ability, audited `print`), opened the way the writer and
  // the prescription list open it. Online only — the tile disables the button with the §6.2 reason offline.
  const openPrescription = (serial: DeskSerial, output: PrescriptionOutput): void => {
    if (!serial.prescription) return;
    const prescription = serial.prescription.public_id;
    window.open(output === 'pdf' ? route('panel.prescription.pdf', { prescription, sync: 1 }) : route('panel.prescription.print', { prescription }), '_blank', 'noopener');
  };

  const doCallNext = (session: BoardSession): void => {
    void run(async () => { const r = await callNext(session.public_id); await desk.refresh(); if (r.called) setToast(t('reception.toast.called', { code: formatBn(r.called.display_code, locale) })); });
  };

  const openKiosk = (session: BoardSession): void => {
    setKiosk({ open: true, url: null, hours: 12 });
    fetchKioskUrl(session.public_id).then((r) => setKiosk({ open: true, url: r.url, hours: r.expires_in_hours })).catch(() => setKiosk((k) => ({ ...k, url: null })));
  };

  const register = (name: string): void => {
    if (!shared.branch || !tenant_public_id || !actor_public_id) return;
    void run(async () => {
      const branchPublicId = desk.board.branch.public_id;
      const r = await registerDevice({ name, branch: branchPublicId, kind: 'reception', device_fingerprint: await deviceFingerprint(), app_version: APP_VERSION });
      await desk.registerFromResponse(r, tenant_public_id, actor_public_id);
      setRegistering(false);
    }, t('reception.device.registered'));
  };

  return (
    <Stack spacing={2}>
      <Stack direction={{ xs: 'column', md: 'row' }} spacing={1} sx={{ alignItems: { md: 'center' } }}>
        <Typography variant="h5" component="h1" sx={{ flexGrow: 1 }}>{desk.board.branch.name} · {formatBn(desk.board.date, locale)}</Typography>
        {desk.registered && desk.device ? <Chip size="small" icon={<DevicesIcon />} label={t('reception.device.this', { name: desk.device.name, number: formatBn(desk.device.number, locale) })} /> : can.register_device ? <Button size="small" startIcon={<DevicesIcon />} onClick={() => setRegistering(true)} disabled={offline}>{t('reception.device.register')}</Button> : null}
        {conflicts.cards.length > 0 ? <Button size="small" color="error" variant="contained" onClick={() => conflicts.show()}>{t('connection.conflicts', { count: formatBn(conflicts.cards.length, locale) })}</Button> : null}
        <Button size="small" startIcon={<RefreshIcon />} onClick={() => void desk.refresh()} disabled={busy}>{t('common.actions.retry')}</Button>
        <Button size="small" component={RouterLink} href={route('panel.reception.shift')} startIcon={<ReportIcon />}>{t('reception.shift.title')}</Button>
      </Stack>

      {isScoped ? (
        <Alert severity="info" data-testid="desk-scope">
          {scopeDoctors.length === 0
            ? t('reception.scope.none')
            : t(scopeDoctors.length === 1 ? 'reception.scope.for_doctor' : 'reception.scope.for_doctors', { doctors: scopeDoctors.join(', ') })}
        </Alert>
      ) : null}
      {error ? <Alert severity="error" onClose={() => setError(null)}>{error}</Alert> : null}
      {!desk.registered && can.register_device ? <Alert severity="info">{t('reception.device.not_registered')}</Alert> : null}

      <Grid container spacing={2}>
        <Grid size={{ xs: 12, md: sidebar ? 8 : 12 }}>
          <Stack spacing={2}>
            {desk.board.sessions.length === 0 ? <Typography color="text.secondary">{t('reception.board.empty')}</Typography> : null}
            {desk.board.sessions.map((s) => (
              <SessionTile key={s.public_id} session={s} mode={desk.mode} blockRemaining={blockRemaining(s)} can={can} busy={busy}
                onBook={(session, ch) => setBooking({ session, channel: ch })} onCallNext={doCallNext} onCheckIn={checkIn}
                onCollect={(session, serial) => setCollect({ session, serial })} onPrint={print} onCancel={(session, serial) => setCancel({ session, serial })}
                onVitals={(_session, serial) => openVitals(serial)} onPrintPrescription={(_session, serial, output) => openPrescription(serial, output)} onKiosk={openKiosk}
                onHoldExpired={() => { void desk.refresh(); }} />
            ))}
          </Stack>
        </Grid>
        {sidebar ? (
        <Grid size={{ xs: 12, md: 4 }}>
          {/* The whole card, not just the box: the dues panel's only input is a patient picked in this search, so
              without it the panel is a card that can never say anything. A desk that may not look patients up
              (the compounder: no `patients.view`, and PatientLookupController authorises exactly that) gets a
              shorter column instead of a search that answers "no patients" to every query it swallows a 403 on. */}
          {can.search_patients ? (
            <Paper variant="outlined" sx={{ p: 2 }}>
              <Typography variant="subtitle1" sx={{ mb: 1 }}>{t('reception.search.title')}</Typography>
              <PatientQuickSearch mode={desk.mode} cached={desk.cachedPatients} onPick={(hit: QuickSearchHit) => { setDuesPatient(hit.publicId); setToast(t('reception.search.picked', { name: hit.name })); }} />
              {canSeeDues && !offline ? <Box sx={{ mt: 1.5 }}><Suspense fallback={null}><PatientDuesPanel patient={duesPatient} /></Suspense></Box> : null}
            </Paper>
          ) : null}
          {activeBlocks.length > 0 ? (
            <Paper variant="outlined" sx={{ p: 2, mt: 2 }}>
              <Typography variant="subtitle2">{t('reception.board.blocks_title')}</Typography>
              {activeBlocks.map((b) => <Typography key={b.publicId} variant="body2">{b.sessionCode}: {formatBn(`${b.nextNumber}–${b.rangeEnd}`, locale)}</Typography>)}
            </Paper>
          ) : null}
        </Grid>
        ) : null}
      </Grid>

      <Suspense fallback={null}>
      {booking !== null ? <BookingDialog open session={booking?.session ?? null} channel={booking?.channel ?? 'counter'} mode={desk.mode} blockRemaining={booking ? blockRemaining(booking.session) : 0}
        onClose={() => setBooking(null)} issueOffline={desk.issueOffline} cachedPatients={desk.cachedPatients}
        onBooked={({ serial, local, session }) => {
          const code = serial?.display_code ?? local?.displayCode ?? '';
          setToast(t('reception.toast.booked', { code: formatBn(code, locale) }));
          if (serial) { void desk.refresh(); print(session, serial); }
          else if (local) print(session, { public_id: local.publicId, display_code: local.displayCode, number: local.number, position: local.position, status: 'booked', priority: local.priority as DeskSerial['priority'], source: 'offline', pool: 'counter', patient_id: null, patient: { public_id: local.patientRef, name: local.patientName, mobile_masked: local.mobileMasked, age_text: null, sex: null, patient_code: '' }, appointment: { public_id: '', type: 'new', channel: 'offline', status: 'confirmed', fee_paisa: local.feePaisa ?? 0, list_fee_paisa: local.feePaisa ?? 0, fee_rule: 'new', payment_status: 'unpaid', hold_expires_at: null }, vitals: null, prescription: null, appointment_id: null, slot_start_at: null, booked_at: '', checked_in_at: null, called_at: null, completed_at: null, no_show_at: null, cancelled_at: null, cancel_reason_code: null, passed_count: 0, skip_count: 0, eta: null });
        }} /> : null}
      {collect !== null ? <CollectFeeDialog open serial={collect?.serial ?? null} offline={offline} busy={busy} error={error} onClose={() => setCollect(null)} onCollect={doCollect} /> : null}
      {cancel !== null ? <CancelDialog open serial={cancel?.serial ?? null} busy={busy} error={error} onClose={() => setCancel(null)} onCancel={doCancel} /> : null}
      {registering ? <DeviceRegistrationDialog open busy={busy} error={error} branchName={desk.board.branch.name} onClose={() => setRegistering(false)} onRegister={register} /> : null}
      {kiosk.open ? <KioskQrDialog open url={kiosk.url} hours={kiosk.hours} onClose={() => setKiosk((k) => ({ ...k, open: false }))} /> : null}
      {conflicts.open ? <ConflictCards open cards={conflicts.cards} index={conflicts.index} busy={conflicts.busy} error={conflicts.error} isAdmin={isAdmin}
        onClose={() => conflicts.hide()} onNext={() => conflicts.next()}
        onResolve={(card, resolution, params) => { conflicts.setBusy(true); desk.resolve(card.clientEventId, resolution, params).then(() => conflicts.setBusy(false)).catch((e: unknown) => conflicts.setBusy(false, isApiError(e) ? e.message : String(e))); }} /> : null}
      {slip.key === 0 ? null : <TokenSlip template={template} data={slip.data} locale={locale} labels={labels} printKey={slip.key} />}
      </Suspense>

      <Snackbar open={toast !== null} autoHideDuration={4000} onClose={() => setToast(null)} message={toast} />
      <Box sx={{ display: 'none' }}><Link href={route('panel.reception.devices.index')}>devices</Link></Box>
    </Stack>
  );
}

Board.layout = (page: ReactNode) => <PanelLayout title="reception.board.title">{page}</PanelLayout>;
