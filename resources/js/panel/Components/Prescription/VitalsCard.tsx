// Vitals (§4.2): the compounder entered them, the doctor reviews. Read-only with a ☐ Reviewed tick (one click) and
// an edit pencil; edits PATCH the same row (edited_by_doctor). BMI is computed here too so it moves while typing.
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Checkbox from '@mui/material/Checkbox';
import Chip from '@mui/material/Chip';
import FormControlLabel from '@mui/material/FormControlLabel';
import IconButton from '@mui/material/IconButton';
import Paper from '@mui/material/Paper';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import EditIcon from '@mui/icons-material/Edit';
import type { VitalsRow } from '@shared/types/models';
import { recordVitals, updateVitals } from '@panel/api/prescription';
import { bmiOf, draftFrom, draftNumber, draftToInput, VITALS_FIELDS, type VitalsDraft } from '@panel/lib/prescription/vitals';

const FIELDS = VITALS_FIELDS;

export interface VitalsCardProps {
  visitId: string;
  vitals: VitalsRow | null;
  reviewed: boolean;
  ageYears: number | null;
  onChange: (vitals: VitalsRow) => void;
  onReviewed: () => void;
  containerRef?: React.RefObject<HTMLDivElement | null>;
}

export function VitalsCard({ visitId, vitals, reviewed, ageYears, onChange, onReviewed, containerRef }: VitalsCardProps) {
  const { t } = useTranslation();
  const [editing, setEditing] = useState(vitals === null);
  // Roving tabindex: §1.3 keeps Vitals OUT of the writer's Tab order (it is compounder data, reached with Alt+2 or
  // a click). Once the card itself has focus its controls join the tab sequence, so nothing becomes unreachable.
  const [inside, setInside] = useState(false);
  const roving = inside ? 0 : -1;
  const [form, setForm] = useState<VitalsDraft>({});
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (vitals === null) return;
    setForm(draftFrom(vitals));
  }, [vitals]);

  const bmi = editing ? bmiOf(draftNumber(form, 'weight_kg'), draftNumber(form, 'height_cm')) : vitals?.bmi ?? null;
  const pediatricWeightMissing = ageYears !== null && ageYears < 12 && (vitals?.weight_kg ?? draftNumber(form, 'weight_kg')) === null;

  const save = async (): Promise<void> => {
    setBusy(true);
    try {
      const body = draftToInput(form);
      const row = vitals === null ? await recordVitals(visitId, body) : await updateVitals(vitals.id, body);
      onChange(row);
      setEditing(false);
    } finally {
      setBusy(false);
    }
  };

  const summary: Array<[string, string]> = vitals === null ? [] : [
    [t('prescriptions.vitals.bp'), vitals.bp_systolic != null && vitals.bp_diastolic != null ? `${vitals.bp_systolic}/${vitals.bp_diastolic}` : '—'],
    [t('prescriptions.vitals.pulse'), vitals.pulse_bpm != null ? `${vitals.pulse_bpm}` : '—'],
    [t('prescriptions.vitals.temperature'), vitals.temperature_c != null ? `${vitals.temperature_c} °C` : '—'],
    [t('prescriptions.vitals.spo2'), vitals.spo2_percent != null ? `${vitals.spo2_percent}%` : '—'],
    [t('prescriptions.vitals.weight'), vitals.weight_kg != null ? `${vitals.weight_kg} kg` : '—'],
    [t('prescriptions.vitals.height'), vitals.height_cm != null ? `${vitals.height_cm} cm` : '—'],
  ];

  return (
    <Paper
      variant="outlined"
      sx={{ px: 1, py: 0.5 }}
      ref={containerRef}
      tabIndex={-1}
      onFocus={() => setInside(true)}
      onBlur={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget as Node | null)) setInside(false);
      }}
    >
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, flexWrap: 'wrap' }}>
        <Typography variant="caption" sx={{ fontWeight: 700, minWidth: 92, color: 'text.secondary' }}>
          {t('prescriptions.writer.zones.vitals')}
        </Typography>

        {!editing ? (
          <>
            {summary.map(([label, value]) => (
              <Box key={label} sx={{ display: 'flex', gap: 0.5, alignItems: 'baseline' }}>
                <Typography variant="caption" color="text.secondary">
                  {label}
                </Typography>
                <Typography variant="body2" sx={{ fontWeight: 600 }}>
                  {value}
                </Typography>
              </Box>
            ))}
            {bmi !== null ? <Chip size="small" label={`BMI ${bmi}`} sx={{ height: 20 }} color={bmi >= 25 ? 'warning' : 'default'} /> : null}
            {vitals?.recorded_by ? (
              <Typography variant="caption" color="text.secondary">
                {t('prescriptions.vitals.recorded_by', { name: vitals.recorded_by.name })}
              </Typography>
            ) : null}
            <Box sx={{ flexGrow: 1 }} />
            <FormControlLabel
              control={<Checkbox size="small" checked={reviewed} disabled={reviewed || vitals === null} onChange={onReviewed} slotProps={{ input: { 'aria-label': t('prescriptions.vitals.reviewed'), tabIndex: roving } }} />}
              label={<Typography variant="caption">{t('prescriptions.vitals.reviewed')}</Typography>}
              sx={{ mr: 0 }}
            />
            <IconButton tabIndex={roving} size="small" onClick={() => setEditing(true)} aria-label={t('common.actions.edit')}>
              <EditIcon fontSize="inherit" />
            </IconButton>
          </>
        ) : (
          <>
            {FIELDS.map((field) => (
              <TextField
                key={String(field.key)}
                size="small"
                variant="standard"
                label={t(field.labelKey)}
                value={form[field.key] ?? ''}
                onChange={(e) => setForm({ ...form, [field.key]: e.target.value })}
                sx={{ width: field.width }}
                slotProps={{ htmlInput: { inputMode: 'decimal', 'aria-label': t(field.labelKey), tabIndex: roving } }}
              />
            ))}
            {bmi !== null ? <Chip size="small" label={`BMI ${bmi}`} sx={{ height: 20 }} /> : null}
            <Box sx={{ flexGrow: 1 }} />
            <Button tabIndex={roving} size="small" onClick={() => setEditing(false)}>
              {t('common.actions.cancel')}
            </Button>
            <Button tabIndex={roving} size="small" variant="contained" disabled={busy} onClick={() => void save()}>
              {t('common.actions.save')}
            </Button>
          </>
        )}
      </Box>
      {pediatricWeightMissing ? (
        <Alert severity="warning" sx={{ py: 0, mt: 0.5 }} variant="outlined">
          <Typography variant="caption">{t('prescriptions.vitals.weight_needed')}</Typography>
        </Alert>
      ) : null}
    </Paper>
  );
}

export default VitalsCard;
