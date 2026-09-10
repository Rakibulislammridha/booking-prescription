// The compounder's vitals screen (BRIEF §5.G.2: vitals are "entered by the compounder before the doctor sees the
// patient"). One click from the checked-in row on the board lands here; saving POSTs the Prescription module's own
// `panel.prescription.vitals.store` endpoint, so `RecordVitals` — and its BMI, its audit row and its permission —
// is the single write path. The desk is offline-capable but this screen is not: clinical bodies must not sit in a
// tablet's IndexedDB (OFFLINE.md §6.2, BRIEF §5.N), so saving is blocked and says so while the desk is offline.
// Temperature is taken in °F (the field says so, the example says so, a °C-looking value is called out); the
// server converts it once to the °C the row stores, and the reading list shows °F again from that °C.
import { useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Divider from '@mui/material/Divider';
import Grid from '@mui/material/Grid';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import DoneIcon from '@mui/icons-material/CheckCircle';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { recordVitals, updateVitals } from '@panel/api/prescription';
import { bmiOf, draftFrom, draftIsEmpty, draftNumber, draftToInput, fieldPlaceholder, fieldUnit, temperatureHint, VITALS_FIELDS, type VitalsDraft, type VitalsMeasurementKey } from '@panel/lib/prescription/vitals';
import { useConnection, selectMode } from '@shared/connection/store';
import { isAllowedOffline, offlineReason } from '@shared/offline';
import { formatBn } from '@shared/format/number';
import { formatTemperature } from '@shared/format/temperature';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { isApiError } from '@shared/http';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type { VitalsRow } from '@shared/types/models';

type Props = PageProps<{
  visit: { public_id: string; started_at: string; status: string };
  serial: { display_code: string; status: string } | null;
  doctor: { name: string; name_bn: string | null };
  session_code: string | null;
  patient: { public_id: string; name: string; patient_code: string; mobile_masked: string; age_text: string | null; sex: string | null; age_years: number | null; blood_group: string | null };
  vitals: VitalsRow[];
}>;

export default function Vitals({ visit, serial, doctor, session_code, patient, vitals: initial }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const mode = useConnection(selectMode);
  const canSave = isAllowedOffline('prescription', mode);
  const [rows, setRows] = useState<VitalsRow[]>(initial);
  const latest = rows[0];
  const amending = latest !== undefined && latest.reviewed_by_doctor_at === null ? latest : null;
  // Prefill from the newest reading, but only while the doctor has not reviewed it — after that a save is a new row.
  const [form, setForm] = useState<VitalsDraft>(() => draftFrom(amending));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  const bmi = bmiOf(draftNumber(form, 'weight_kg'), draftNumber(form, 'height_cm'));
  const pediatricWeightMissing = patient.age_years !== null && patient.age_years < 12 && draftNumber(form, 'weight_kg') === null;
  const empty = useMemo(() => draftIsEmpty(form), [form]);
  const celsiusTyped = temperatureHint(form, locale);
  const doctorName = locale === 'bn' && doctor.name_bn ? doctor.name_bn : doctor.name;

  const submit = (event: FormEvent): void => {
    event.preventDefault();
    if (busy || empty || !canSave) return;
    setBusy(true);
    setError(null);
    void (async () => {
      try {
        const body = draftToInput(form);
        const row = amending === null ? await recordVitals(visit.public_id, body) : await updateVitals(amending.id, body);
        setRows((current) => [row, ...current.filter((r) => r.id !== row.id)]);
        setSaved(true);
      } catch (e) {
        setError(isApiError(e) ? e.message : e instanceof Error ? e.message : String(e));
      } finally {
        setBusy(false);
      }
    })();
  };

  const set = (key: VitalsMeasurementKey, value: string): void => {
    setSaved(false);
    setForm({ ...form, [key]: value });
  };

  return (
    <Stack spacing={2} sx={{ maxWidth: 880 }}>
      <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
        <Button size="small" startIcon={<ArrowBackIcon />} component={RouterLink} href={route('panel.reception.board')}>
          {t('reception.vitals.back')}
        </Button>
        <Box sx={{ flexGrow: 1 }} />
        {serial ? <Chip color="secondary" label={formatBn(serial.display_code, locale)} sx={{ fontFamily: 'monospace', fontWeight: 700 }} /> : null}
      </Stack>

      <Card>
        <CardContent>
          <Typography variant="h5" component="h1" lang="bn">{patient.name}</Typography>
          <Typography variant="body2" color="text.secondary">
            {[patient.patient_code, patient.age_text ? formatBn(patient.age_text, locale) : null, patient.sex ? t(`patients.gender.${patient.sex}`) : null, patient.blood_group, patient.mobile_masked]
              .filter(Boolean).join(' · ')}
          </Typography>
          <Typography variant="body2" sx={{ mt: 0.5 }}>
            {t('reception.vitals.for_doctor', { doctor: doctorName, session: session_code ?? '—' })}
          </Typography>
        </CardContent>
      </Card>

      {!canSave ? <Alert severity="warning">{t(offlineReason('prescription'))}</Alert> : null}
      {error ? <Alert severity="error" onClose={() => setError(null)}>{error}</Alert> : null}
      {saved ? <Alert severity="success" icon={<DoneIcon fontSize="inherit" />}>{t('reception.vitals.saved')}</Alert> : null}

      <Card component="form" onSubmit={submit}>
        <CardContent>
          <Typography variant="subtitle1" sx={{ fontWeight: 600 }}>{t('reception.vitals.title')}</Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>{t('reception.vitals.hint')}</Typography>

          <Grid container spacing={2}>
            {VITALS_FIELDS.map((field, index) => {
              const unit = fieldUnit(field, locale);
              const hint = field.key === 'temperature_f' ? celsiusTyped : null;

              return (
                <Grid key={String(field.key)} size={{ xs: 6, sm: 4, md: 3 }}>
                  <TextField
                    fullWidth
                    autoFocus={index === 0}
                    label={unit === '' ? t(field.labelKey) : `${t(field.labelKey)} (${unit})`}
                    value={form[field.key] ?? ''}
                    placeholder={fieldPlaceholder(field, locale)}
                    error={hint !== null}
                    helperText={hint === null ? undefined : t('prescriptions.vitals.temperature_hint', hint)}
                    onChange={(e) => set(field.key, e.target.value)}
                    slotProps={{ htmlInput: { inputMode: 'decimal', 'aria-label': t(field.labelKey) }, input: { endAdornment: unit ? <Typography variant="caption" color="text.secondary">{unit}</Typography> : null } }}
                  />
                </Grid>
              );
            })}
            <Grid size={{ xs: 12, md: 6 }}>
              <TextField fullWidth label={t('reception.vitals.notes')} value={form.notes ?? ''} onChange={(e) => { setSaved(false); setForm({ ...form, notes: e.target.value }); }} slotProps={{ htmlInput: { maxLength: 255 } }} />
            </Grid>
            <Grid size={{ xs: 12, md: 6 }} sx={{ display: 'flex', alignItems: 'center' }}>
              <Chip color={bmi !== null && bmi >= 25 ? 'warning' : 'default'} label={bmi === null ? t('reception.vitals.bmi_pending') : `BMI ${formatBn(bmi, locale)}`} />
            </Grid>
          </Grid>

          {pediatricWeightMissing ? <Alert severity="warning" variant="outlined" sx={{ mt: 2 }}>{t('prescriptions.vitals.weight_needed')}</Alert> : null}

          <Stack direction="row" spacing={1} sx={{ mt: 3, alignItems: 'center' }}>
            <Button type="submit" size="large" variant="contained" disabled={busy || empty || !canSave}>
              {amending === null ? t('reception.vitals.save') : t('reception.vitals.update')}
            </Button>
            <Typography variant="caption" color="text.secondary">{t('reception.vitals.doctor_reviews')}</Typography>
          </Stack>
        </CardContent>
      </Card>

      <Card>
        <CardContent>
          <Typography variant="subtitle1" sx={{ fontWeight: 600, mb: 1 }}>{t('reception.vitals.recorded_title')}</Typography>
          {rows.length === 0 ? (
            <Typography variant="body2" color="text.secondary">{t('reception.vitals.none_yet')}</Typography>
          ) : (
            <Stack divider={<Divider flexItem />} spacing={1}>
              {rows.map((row) => (
                <Box key={row.id} sx={{ display: 'flex', gap: 1, flexWrap: 'wrap', alignItems: 'center', pt: 1 }}>
                  <Typography variant="body2" sx={{ minWidth: 132 }}>{row.recorded_at === null ? '—' : formatBn(formatDhaka(row.recorded_at, 'D MMM, h:mm a', locale), locale)}</Typography>
                  <Typography variant="body2" sx={{ fontWeight: 600 }}>
                    {[
                      row.bp_systolic !== null && row.bp_diastolic !== null ? `${t('prescriptions.vitals.bp')} ${formatBn(`${row.bp_systolic}/${row.bp_diastolic}`, locale)}` : null,
                      row.pulse_bpm !== null ? `${t('prescriptions.vitals.pulse')} ${formatBn(row.pulse_bpm, locale)}` : null,
                      formatTemperature(row.temperature_c, locale),
                      row.spo2_percent !== null ? `SpO₂ ${formatBn(row.spo2_percent, locale)}%` : null,
                      row.weight_kg !== null ? `${formatBn(row.weight_kg, locale)} kg` : null,
                      row.bmi !== null ? `BMI ${formatBn(row.bmi, locale)}` : null,
                    ].filter(Boolean).join(' · ') || '—'}
                  </Typography>
                  <Box sx={{ flexGrow: 1 }} />
                  {row.recorded_by ? <Typography variant="caption" color="text.secondary">{t('prescriptions.vitals.recorded_by', { name: row.recorded_by.name })}</Typography> : null}
                  {row.reviewed_by_doctor_at !== null
                    ? <Chip size="small" color="success" variant="outlined" label={t('reception.vitals.reviewed_at', { at: formatDhaka(row.reviewed_by_doctor_at, 'D MMM, h:mm a', locale) })} />
                    : <Chip size="small" variant="outlined" label={t('reception.vitals.awaiting_review')} />}
                  {row.edited_by_doctor ? <Chip size="small" color="info" variant="outlined" label={t('reception.vitals.edited_by_doctor')} /> : null}
                </Box>
              ))}
            </Stack>
          )}
        </CardContent>
      </Card>
    </Stack>
  );
}

Vitals.layout = (page: ReactNode) => <PanelLayout title="reception.vitals.title">{page}</PanelLayout>;
