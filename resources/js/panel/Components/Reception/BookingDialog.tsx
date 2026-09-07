// The one-click counter booking dialog (BRIEF §5.F): mobile → household match → name/sex/age for a new person →
// allocate (online: BookAppointment through the panel endpoint; offline: BlockIssuer + register_patient stub).
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Dialog from '@mui/material/Dialog';
import DialogTitle from '@mui/material/DialogTitle';
import DialogContent from '@mui/material/DialogContent';
import DialogActions from '@mui/material/DialogActions';
import Button from '@mui/material/Button';
import TextField from '@mui/material/TextField';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Alert from '@mui/material/Alert';
import Chip from '@mui/material/Chip';
import Typography from '@mui/material/Typography';
import { householdByMobile } from '@panel/api/patients';
import { storeCounterBooking } from '@panel/api/booking';
import { isApiError } from '@shared/http';
import { ulid } from '@shared/ulid';
import { formatBn, toAsciiDigits } from '@shared/format/number';
import { formatBdt } from '@shared/format/money';
import { getLocale } from '@shared/locale';
import type { ConnectionMode } from '@shared/connection/store';
import type { BoardSession, CounterBookingResponse, FamilyMember } from '@shared/types/models';
import type { CachedSerial } from '@shared/offline';
import type { DeskIssueInput } from '@panel/hooks/reception/useDesk';

export interface BookingDialogProps {
  open: boolean;
  session: BoardSession | null;
  channel: 'counter' | 'walkin' | 'phone' | 'followup';
  mode: ConnectionMode;
  blockRemaining: number;
  onClose(): void;
  onBooked(result: { serial: CounterBookingResponse['serial'] | null; local: CachedSerial | null; session: BoardSession }): void;
  issueOffline(input: DeskIssueInput): Promise<CachedSerial>;
  cachedPatients(q: string): Promise<Array<{ publicId: string; name: string; mobile: string; ageText?: string | null }>>;
}

const BD_MOBILE = /^(\+?88)?01[3-9]\d{8}$/;

export function BookingDialog({ open, session, channel, mode, blockRemaining, onClose, onBooked, issueOffline, cachedPatients }: BookingDialogProps) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [mobile, setMobile] = useState('');
  const [household, setHousehold] = useState<Array<{ publicId: string; name: string; ageText?: string | null; relation?: string | null }>>([]);
  const [patient, setPatient] = useState<string | null>(null);   // chosen household member
  const [name, setName] = useState('');
  const [sex, setSex] = useState<'' | 'm' | 'f' | 'o'>('');
  const [age, setAge] = useState('');
  const [priority, setPriority] = useState<'normal' | 'elderly' | 'emergency' | 'vip'>('normal');
  const [type, setType] = useState<'new' | 'followup'>(channel === 'followup' ? 'followup' : 'new');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const clientEventId = useMemo(() => ulid(), [open]); // eslint-disable-line react-hooks/exhaustive-deps
  const offline = mode === 'offline';
  const normalised = toAsciiDigits(mobile).replace(/[\s-]/g, '');
  const mobileValid = BD_MOBILE.test(normalised);

  useEffect(() => {
    if (!open) { setMobile(''); setHousehold([]); setPatient(null); setName(''); setSex(''); setAge(''); setPriority('normal'); setError(null); setType(channel === 'followup' ? 'followup' : 'new'); }
  }, [open, channel]);

  useEffect(() => {
    if (!mobileValid) { setHousehold([]); setPatient(null); return undefined; }
    let cancelled = false;
    const run = offline
      ? cachedPatients(normalised).then((rows) => rows.filter((r) => r.mobile.replace(/\D/g, '').endsWith(normalised.slice(-10))).map((r) => ({ publicId: r.publicId, name: r.name, ageText: r.ageText })))
      : householdByMobile(normalised).then((r) => r.data.map((p: FamilyMember) => ({ publicId: p.public_id, name: p.name, ageText: p.age_text, relation: p.relation })));
    run.then((rows) => { if (!cancelled) { setHousehold(rows); setPatient(rows.length === 1 ? (rows[0]?.publicId ?? null) : null); } }).catch(() => undefined);
    return () => { cancelled = true; };
  }, [normalised, mobileValid, offline, cachedPatients]);

  const fee = session ? (type === 'followup' ? session.fee_followup_paisa : session.fee_new_paisa) : 0;
  const newPerson = patient === null;
  const canSubmit = Boolean(session) && mobileValid && (!newPerson || name.trim() !== '') && !busy && (!offline || blockRemaining > 0);

  const submit = async (): Promise<void> => {
    if (!session) return;
    setBusy(true);
    setError(null);
    try {
      if (offline) {
        const chosen = household.find((h) => h.publicId === patient);
        const local = await issueOffline({
          session, patientRef: chosen?.publicId ?? '', patientName: chosen?.name ?? name.trim(), mobileMasked: normalised.slice(0, 3) + '*****' + normalised.slice(-3),
          priority, appointmentType: type, feeAmountPaisa: fee,
          ...(chosen ? {} : { stub: { localId: ulid(), mobile: normalised, name: name.trim(), ...(sex ? { sex } : {}), ...(age ? { ageYears: Number(toAsciiDigits(age)) } : {}) } }),
        });
        onBooked({ serial: null, local, session });
      } else {
        const result = await storeCounterBooking({
          session: session.public_id, channel, mobile: normalised, patient, name: newPerson ? name.trim() : null, sex: newPerson && sex ? sex : null,
          age_years: newPerson && age ? Number(toAsciiDigits(age)) : null, priority, type: channel === 'walkin' ? null : type, client_event_id: clientEventId,
        });
        onBooked({ serial: result.serial, local: null, session });
      }
      onClose();
    } catch (e) {
      setError(isApiError(e) ? (e.is('booking.patient_ambiguous') ? t('booking.errors.patient_ambiguous') : t(`serials.errors.${e.code ?? 'unknown'}`, { defaultValue: e.message })) : String(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog open={open} onClose={busy ? undefined : onClose} fullWidth maxWidth="sm" onKeyDown={(e) => { if (e.key === 'Enter' && canSubmit) void submit(); }}>
      <DialogTitle>{t(channel === 'walkin' ? 'serials.actions.issue_walkin' : 'reception.booking.title')}{session ? ` — ${t('reception.board.session', { code: session.code })}` : ''}</DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ pt: 1 }}>
          {offline ? <Alert severity="warning">{t('reception.booking.offline_notice', { count: formatBn(blockRemaining, locale) })}</Alert> : null}
          {error ? <Alert severity="error" onClose={() => setError(null)}>{error}</Alert> : null}
          <TextField label={t('auth.mobile')} value={mobile} onChange={(e) => setMobile(e.target.value)} autoFocus inputMode="tel" placeholder="01XXXXXXXXX" error={mobile !== '' && !mobileValid} />
          {household.length > 0 ? (
            <Stack spacing={1}>
              <Typography variant="subtitle2">{t('reception.booking.household')}</Typography>
              <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap' }}>
                {household.map((h) => <Chip key={h.publicId} label={`${h.name}${h.ageText ? ` · ${formatBn(h.ageText, locale)}` : ''}`} color={patient === h.publicId ? 'primary' : 'default'} onClick={() => setPatient(h.publicId)} />)}
                <Chip label={t('reception.booking.new_member')} variant="outlined" color={patient === null ? 'primary' : 'default'} onClick={() => setPatient(null)} />
              </Stack>
            </Stack>
          ) : null}
          {newPerson ? (
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1}>
              <TextField label={t('booking.form.name')} value={name} onChange={(e) => setName(e.target.value)} fullWidth slotProps={{ htmlInput: { lang: 'bn' } }} />
              <TextField select label={t('booking.form.sex')} value={sex} onChange={(e) => setSex(e.target.value as '' | 'm' | 'f' | 'o')} sx={{ minWidth: 110 }}>
                <MenuItem value="">—</MenuItem>
                <MenuItem value="m">{t('patients.gender.male')}</MenuItem>
                <MenuItem value="f">{t('patients.gender.female')}</MenuItem>
                <MenuItem value="o">{t('patients.gender.other')}</MenuItem>
              </TextField>
              <TextField label={t('booking.form.age')} value={age} onChange={(e) => setAge(e.target.value)} inputMode="numeric" sx={{ minWidth: 90 }} />
            </Stack>
          ) : null}
          <Stack direction="row" spacing={1}>
            <TextField select label={t('reception.booking.priority')} value={priority} onChange={(e) => setPriority(e.target.value as typeof priority)} fullWidth>
              {(['normal', 'elderly', 'emergency', 'vip'] as const).map((p) => <MenuItem key={p} value={p}>{t(`serials.priority.${p}`)}</MenuItem>)}
            </TextField>
            {channel !== 'walkin' ? (
              <TextField select label={t('reception.booking.type')} value={type} onChange={(e) => setType(e.target.value as 'new' | 'followup')} fullWidth>
                <MenuItem value="new">{t('reception.booking.type_new')}</MenuItem>
                <MenuItem value="followup">{t('reception.booking.type_followup')}</MenuItem>
              </TextField>
            ) : null}
          </Stack>
          <Typography variant="body2" color="text.secondary">{t('reception.booking.fee_hint', { fee: formatBdt(fee, locale) })}</Typography>
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={busy}>{t('common.actions.cancel')}</Button>
        <Button variant="contained" onClick={() => void submit()} disabled={!canSubmit}>{offline ? t('connection.issue_offline_block') : t('reception.booking.submit')}</Button>
      </DialogActions>
    </Dialog>
  );
}
