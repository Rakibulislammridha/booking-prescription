// Create a per-date override (schedule_overrides) as an Inertia form post.
import { useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Dialog from '@mui/material/Dialog';
import DialogTitle from '@mui/material/DialogTitle';
import DialogContent from '@mui/material/DialogContent';
import DialogActions from '@mui/material/DialogActions';
import Button from '@mui/material/Button';
import TextField from '@mui/material/TextField';
import MenuItem from '@mui/material/MenuItem';
import FormControlLabel from '@mui/material/FormControlLabel';
import Switch from '@mui/material/Switch';
import Box from '@mui/material/Box';
import Alert from '@mui/material/Alert';
import { route } from '@shared/routes';
import type { OverrideType } from '@shared/types/models';

export interface OverrideDialogProps {
  open: boolean;
  onClose: () => void;
  doctorId: number;
  branchId: number;
  today: string;
  sessionCodes: string[];
}

const TYPES: OverrideType[] = ['late_start', 'cut_short', 'cancelled', 'capacity_change', 'time_change', 'extra_session'];

interface FormData {
  doctor_id: number;
  branch_id: number;
  override_date: string;
  session_code: string;
  type: OverrideType;
  delay_minutes: number | '';
  new_start_time: string;
  new_end_time: string;
  new_counter_quota: number | '';
  new_online_quota: number | '';
  new_buffer_quota: number | '';
  reason: string;
  notify_patients: boolean;
}

export function OverrideDialog({ open, onClose, doctorId, branchId, today, sessionCodes }: OverrideDialogProps) {
  const { t } = useTranslation();
  const form = useForm<FormData>({
    doctor_id: doctorId, branch_id: branchId, override_date: today, session_code: '', type: 'late_start', delay_minutes: 30,
    new_start_time: '', new_end_time: '', new_counter_quota: '', new_online_quota: '', new_buffer_quota: '', reason: '', notify_patients: true,
  });
  const { setData, reset } = form;

  useEffect(() => {
    if (open) {
      reset();
      setData((d) => ({ ...d, doctor_id: doctorId, branch_id: branchId, override_date: today }));
    }
  }, [open, doctorId, branchId, today, setData, reset]);

  const type = form.data.type;
  const needsTimes = type === 'time_change' || type === 'extra_session';
  const needsEnd = needsTimes || type === 'cut_short';
  const needsQuotas = type === 'capacity_change' || type === 'extra_session';

  const submit = (): void => {
    form.transform((d) => ({
      ...d,
      session_code: d.session_code || null,
      delay_minutes: d.type === 'late_start' && d.delay_minutes !== '' ? d.delay_minutes : null,
      new_start_time: needsTimes && d.new_start_time ? d.new_start_time : null,
      new_end_time: needsEnd && d.new_end_time ? d.new_end_time : null,
      new_counter_quota: needsQuotas && d.new_counter_quota !== '' ? d.new_counter_quota : null,
      new_online_quota: needsQuotas && d.new_online_quota !== '' ? d.new_online_quota : null,
      new_buffer_quota: needsQuotas && d.new_buffer_quota !== '' ? d.new_buffer_quota : null,
      reason: d.reason || null,
    }));
    form.post(route('panel.scheduling.overrides.store'), { preserveScroll: true, onSuccess: () => onClose() });
  };
  const err = (k: keyof FormData) => form.errors[k];

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm" onKeyDown={(e) => { if (e.key === 'Enter' && !form.processing) { e.preventDefault(); submit(); } }}>
      <DialogTitle>{t('scheduling.override.create_title')}</DialogTitle>
      <DialogContent>
        {(form.errors as Record<string, string | undefined>).domain ? <Alert severity="error" sx={{ mb: 2 }}>{(form.errors as Record<string, string | undefined>).domain}</Alert> : null}
        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' }, mt: 1 }}>
          <TextField type="date" label={t('scheduling.override.date')} value={form.data.override_date} onChange={(e) => setData('override_date', e.target.value)} slotProps={{ inputLabel: { shrink: true } }} error={Boolean(err('override_date'))} helperText={err('override_date')} />
          <TextField select label={t('scheduling.override.type')} value={type} onChange={(e) => setData('type', e.target.value as OverrideType)}>
            {TYPES.map((v) => <MenuItem key={v} value={v}>{t(`scheduling.override_type.${v}`)}</MenuItem>)}
          </TextField>
          <TextField select label={t('scheduling.override.session_code')} value={form.data.session_code} onChange={(e) => setData('session_code', e.target.value)} error={Boolean(err('session_code'))} helperText={err('session_code')}>
            {type !== 'extra_session' ? <MenuItem value="">{t('scheduling.override.all_sessions')}</MenuItem> : null}
            {(type === 'extra_session' ? ['A', 'B', 'C', 'D', 'E'] : sessionCodes).map((c) => <MenuItem key={c} value={c}>{c}</MenuItem>)}
          </TextField>
          {type === 'late_start' ? (
            <TextField type="number" label={t('scheduling.override.delay_minutes')} value={form.data.delay_minutes} onChange={(e) => setData('delay_minutes', e.target.value === '' ? '' : Number(e.target.value))} error={Boolean(err('delay_minutes'))} helperText={err('delay_minutes')} />
          ) : null}
          {needsTimes ? (
            <TextField type="time" label={t('scheduling.override.new_start_time')} value={form.data.new_start_time} onChange={(e) => setData('new_start_time', e.target.value)} slotProps={{ inputLabel: { shrink: true } }} error={Boolean(err('new_start_time'))} helperText={err('new_start_time')} />
          ) : null}
          {needsEnd ? (
            <TextField type="time" label={t('scheduling.override.new_end_time')} value={form.data.new_end_time} onChange={(e) => setData('new_end_time', e.target.value)} slotProps={{ inputLabel: { shrink: true } }} error={Boolean(err('new_end_time'))} helperText={err('new_end_time')} />
          ) : null}
          {needsQuotas ? (
            <>
              <TextField type="number" label={t('scheduling.template.counter_quota')} value={form.data.new_counter_quota} onChange={(e) => setData('new_counter_quota', e.target.value === '' ? '' : Number(e.target.value))} error={Boolean(err('new_counter_quota'))} helperText={err('new_counter_quota')} />
              <TextField type="number" label={t('scheduling.template.online_quota')} value={form.data.new_online_quota} onChange={(e) => setData('new_online_quota', e.target.value === '' ? '' : Number(e.target.value))} error={Boolean(err('new_online_quota'))} helperText={err('new_online_quota')} />
              <TextField type="number" label={t('scheduling.template.buffer_quota')} value={form.data.new_buffer_quota} onChange={(e) => setData('new_buffer_quota', e.target.value === '' ? '' : Number(e.target.value))} error={Boolean(err('new_buffer_quota'))} helperText={err('new_buffer_quota')} />
            </>
          ) : null}
          <TextField label={t('scheduling.override.reason')} value={form.data.reason} onChange={(e) => setData('reason', e.target.value)} sx={{ gridColumn: '1 / -1' }} error={Boolean(err('reason'))} helperText={err('reason')} />
          <FormControlLabel control={<Switch checked={form.data.notify_patients} onChange={(e) => setData('notify_patients', e.target.checked)} />} label={t('scheduling.override.notify_patients')} />
        </Box>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>{t('common.actions.cancel')}</Button>
        <Button variant="contained" onClick={submit} disabled={form.processing}>{t('common.actions.save')}</Button>
      </DialogActions>
    </Dialog>
  );
}

export default OverrideDialog;
