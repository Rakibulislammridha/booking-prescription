// Create/edit one weekly template row (doctor_schedules) as an Inertia form post.
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
import type { DoctorSchedule, ScheduleMode } from '@shared/types/models';

export interface ScheduleDialogProps {
  open: boolean;
  onClose: () => void;
  doctorId: number;
  branchId: number;
  weekday: number;
  schedule: DoctorSchedule | null; // null = create
  weekdayLabels: string[];
}

interface FormData {
  doctor_id: number;
  branch_id: number;
  weekday: number;
  session_code: string;
  session_label: string;
  start_time: string;
  end_time: string;
  mode: ScheduleMode;
  slot_minutes: number | '';
  counter_quota: number;
  online_quota: number;
  buffer_quota: number;
  avg_consult_minutes: number;
  auto_noshow_after: number | '';
  works_on_holidays: boolean;
  effective_from: string;
  effective_to: string;
}

function initial(doctorId: number, branchId: number, weekday: number, schedule: DoctorSchedule | null): FormData {
  return {
    doctor_id: doctorId,
    branch_id: branchId,
    weekday: schedule?.weekday ?? weekday,
    session_code: schedule?.session_code ?? 'A',
    session_label: schedule?.session_label ?? '',
    start_time: schedule?.start_time ?? '09:00',
    end_time: schedule?.end_time ?? '13:00',
    mode: schedule?.mode ?? 'serial',
    slot_minutes: schedule?.slot_minutes ?? '',
    counter_quota: schedule?.counter_quota ?? 10,
    online_quota: schedule?.online_quota ?? 10,
    buffer_quota: schedule?.buffer_quota ?? 4,
    avg_consult_minutes: schedule?.avg_consult_minutes ?? 6,
    auto_noshow_after: schedule?.auto_noshow_after ?? '',
    works_on_holidays: schedule?.works_on_holidays ?? false,
    effective_from: schedule?.effective_from ?? '',
    effective_to: schedule?.effective_to ?? '',
  };
}

export function ScheduleDialog({ open, onClose, doctorId, branchId, weekday, schedule, weekdayLabels }: ScheduleDialogProps) {
  const { t } = useTranslation();
  const form = useForm<FormData>(initial(doctorId, branchId, weekday, schedule));
  const { setData, setDefaults, reset } = form;

  // Re-seed the form when the dialog opens or its subject changes — and ONLY then. Inertia's useForm
  // recreates setData/setDefaults/reset whenever the data changes, so listing them as effect deps made
  // this run after every keystroke and reset the field the user had just typed into.
  const subject = `${doctorId}:${branchId}:${weekday}:${schedule?.id ?? 'new'}`;
  useEffect(() => {
    if (open) {
      const next = initial(doctorId, branchId, weekday, schedule);
      setDefaults(next);
      reset();
      setData(next);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- see comment above
  }, [open, subject]);

  const submit = (): void => {
    form.transform((d) => ({
      ...d,
      session_label: d.session_label || null,
      slot_minutes: d.mode === 'slot' && d.slot_minutes !== '' ? d.slot_minutes : null,
      auto_noshow_after: d.auto_noshow_after === '' ? null : d.auto_noshow_after,
      effective_from: d.effective_from || null,
      effective_to: d.effective_to || null,
    }));
    const options = { preserveScroll: true, onSuccess: () => onClose() };
    if (schedule) form.put(route('panel.scheduling.schedules.update', { schedule: schedule.id }), options);
    else form.post(route('panel.scheduling.schedules.store'), options);
  };

  const total = Number(form.data.counter_quota) + Number(form.data.online_quota) + Number(form.data.buffer_quota);
  const err = (k: keyof FormData) => form.errors[k];

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm" onKeyDown={(e) => { if (e.key === 'Enter' && !form.processing) { e.preventDefault(); submit(); } }}>
      <DialogTitle>{schedule ? t('scheduling.template.edit_title') : t('scheduling.template.create_title')}</DialogTitle>
      <DialogContent>
        {(form.errors as Record<string, string | undefined>).domain ? <Alert severity="error" sx={{ mb: 2 }}>{(form.errors as Record<string, string | undefined>).domain}</Alert> : null}
        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' }, mt: 1 }}>
          <TextField select label={t('scheduling.template.weekday')} value={form.data.weekday} onChange={(e) => setData('weekday', Number(e.target.value))} error={Boolean(err('weekday'))} helperText={err('weekday')}>
            {weekdayLabels.map((label, i) => <MenuItem key={i} value={i}>{label}</MenuItem>)}
          </TextField>
          <TextField label={t('scheduling.template.session_code')} value={form.data.session_code} onChange={(e) => setData('session_code', e.target.value.toUpperCase().slice(0, 1))} slotProps={{ htmlInput: { maxLength: 1 } }} error={Boolean(err('session_code'))} helperText={err('session_code') ?? t('scheduling.template.session_code_help')} />
          <TextField label={t('scheduling.template.session_label')} value={form.data.session_label} onChange={(e) => setData('session_label', e.target.value)} error={Boolean(err('session_label'))} helperText={err('session_label')} />
          <TextField select label={t('scheduling.template.mode')} value={form.data.mode} onChange={(e) => setData('mode', e.target.value as ScheduleMode)}>
            <MenuItem value="serial">{t('scheduling.mode.serial')}</MenuItem>
            <MenuItem value="slot">{t('scheduling.mode.slot')}</MenuItem>
          </TextField>
          <TextField type="time" label={t('scheduling.template.start_time')} value={form.data.start_time} onChange={(e) => setData('start_time', e.target.value)} slotProps={{ inputLabel: { shrink: true } }} error={Boolean(err('start_time'))} helperText={err('start_time')} />
          <TextField type="time" label={t('scheduling.template.end_time')} value={form.data.end_time} onChange={(e) => setData('end_time', e.target.value)} slotProps={{ inputLabel: { shrink: true } }} error={Boolean(err('end_time'))} helperText={err('end_time')} />
          {form.data.mode === 'slot' ? (
            <TextField type="number" label={t('scheduling.template.slot_minutes')} value={form.data.slot_minutes} onChange={(e) => setData('slot_minutes', e.target.value === '' ? '' : Number(e.target.value))} error={Boolean(err('slot_minutes'))} helperText={err('slot_minutes')} />
          ) : null}
          <TextField type="number" label={t('scheduling.template.counter_quota')} value={form.data.counter_quota} onChange={(e) => setData('counter_quota', Number(e.target.value))} error={Boolean(err('counter_quota'))} helperText={err('counter_quota')} />
          <TextField type="number" label={t('scheduling.template.online_quota')} value={form.data.online_quota} onChange={(e) => setData('online_quota', Number(e.target.value))} error={Boolean(err('online_quota'))} helperText={err('online_quota')} />
          <TextField type="number" label={t('scheduling.template.buffer_quota')} value={form.data.buffer_quota} onChange={(e) => setData('buffer_quota', Number(e.target.value))} error={Boolean(err('buffer_quota'))} helperText={err('buffer_quota') ?? t('scheduling.template.max_serials', { count: total })} />
          <TextField type="number" label={t('scheduling.template.avg_consult_minutes')} value={form.data.avg_consult_minutes} onChange={(e) => setData('avg_consult_minutes', Number(e.target.value))} error={Boolean(err('avg_consult_minutes'))} helperText={err('avg_consult_minutes')} />
          <TextField type="number" label={t('scheduling.template.auto_noshow_after')} value={form.data.auto_noshow_after} onChange={(e) => setData('auto_noshow_after', e.target.value === '' ? '' : Number(e.target.value))} helperText={t('scheduling.template.auto_noshow_help')} />
          <TextField type="date" label={t('scheduling.template.effective_from')} value={form.data.effective_from} onChange={(e) => setData('effective_from', e.target.value)} slotProps={{ inputLabel: { shrink: true } }} error={Boolean(err('effective_from'))} helperText={err('effective_from')} />
          <TextField type="date" label={t('scheduling.template.effective_to')} value={form.data.effective_to} onChange={(e) => setData('effective_to', e.target.value)} slotProps={{ inputLabel: { shrink: true } }} error={Boolean(err('effective_to'))} helperText={err('effective_to')} />
          <FormControlLabel control={<Switch checked={form.data.works_on_holidays} onChange={(e) => setData('works_on_holidays', e.target.checked)} />} label={t('scheduling.template.works_on_holidays')} />
        </Box>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>{t('common.actions.cancel')}</Button>
        <Button variant="contained" onClick={submit} disabled={form.processing}>{t('common.actions.save')}</Button>
      </DialogActions>
    </Dialog>
  );
}

export default ScheduleDialog;
