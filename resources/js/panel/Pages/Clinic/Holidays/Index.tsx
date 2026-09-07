// Clinic/Holidays/Index — the year view plus the list. Clicking a day opens the add dialog with that date filled in.
import { useState, type ReactNode } from 'react';
import { router, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import IconButton from '@mui/material/IconButton';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import AddIcon from '@mui/icons-material/Add';
import DeleteIcon from '@mui/icons-material/DeleteOutlined';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { HolidayCalendar } from '@panel/Components/Clinic/HolidayCalendar';
import { route } from '@shared/routes';
import { formatDateDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import type { PageProps } from '@shared/types/inertia';
import type { ClinicBranch, ClinicHoliday } from '@shared/types/models';

type Props = PageProps<{
  holidays: ClinicHoliday[];
  /** Named `branch_options`, not `branches`: SharedProps already owns `branches` (the switcher list). */
  branch_options: ClinicBranch[];
  filters: { year: number; branch: number | null };
  today: string;
  can: { manage: boolean };
}>;

interface FormData {
  holiday_date: string;
  name: string;
  name_bn: string;
  branch_id: string;
}

export default function Index({ holidays, branch_options, filters, today, can }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [open, setOpen] = useState(false);
  const form = useForm<FormData>({ holiday_date: today, name: '', name_bn: '', branch_id: '' });

  const reload = (patch: Record<string, string | number | null>): void => {
    const next = { year: filters.year, branch: filters.branch, ...patch };
    router.get(route('panel.clinic.holidays.index'), Object.fromEntries(Object.entries(next).filter(([, v]) => v !== null && String(v) !== '')), { preserveState: true, replace: true });
  };
  const start = (date: string): void => {
    if (!can.manage) return;
    form.clearErrors();
    form.setData((current) => ({ ...current, holiday_date: date, name: '', name_bn: '' }));
    setOpen(true);
  };
  // `domain` is the DomainException bag key (HolidayAlreadyExists), not a field error.
  const domainError = (form.errors as Record<string, string | undefined>).domain;

  const remove = (holiday: ClinicHoliday): void => {
    if (window.confirm(t('clinic.holidays.delete_confirm', { name: holiday.name }))) {
      router.delete(route('panel.clinic.holidays.destroy', { holiday: holiday.id }), { preserveScroll: true });
    }
  };

  return (
    <Stack spacing={2}>
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ alignItems: { sm: 'center' } }}>
        <TextField
          select size="small" label={t('clinic.holidays.filters.year')} value={String(filters.year)} sx={{ minWidth: 140 }}
          onChange={(e) => reload({ year: Number(e.target.value) })}
        >
          {[filters.year - 1, filters.year, filters.year + 1].map((y) => <MenuItem key={y} value={String(y)}>{y}</MenuItem>)}
        </TextField>
        <TextField
          select size="small" label={t('clinic.holidays.filters.branch')} value={filters.branch ? String(filters.branch) : ''} sx={{ minWidth: 200 }}
          onChange={(e) => reload({ branch: e.target.value === '' ? null : Number(e.target.value) })}
        >
          <MenuItem value="">{t('clinic.holidays.all_branches')}</MenuItem>
          {branch_options.map((b) => <MenuItem key={b.public_id} value={String(b.id)}>{b.name}</MenuItem>)}
        </TextField>
        <Box sx={{ flexGrow: 1 }} />
        {can.manage ? <Button variant="contained" startIcon={<AddIcon />} onClick={() => start(today)}>{t('clinic.holidays.add')}</Button> : null}
      </Stack>

      <HolidayCalendar year={filters.year} holidays={holidays} today={today} onPick={start} />

      <Card>
        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small" aria-label={t('clinic.holidays.title')}>
            <TableHead>
              <TableRow>
                <TableCell>{t('clinic.holidays.columns.date')}</TableCell>
                <TableCell>{t('clinic.holidays.columns.name')}</TableCell>
                <TableCell>{t('clinic.holidays.columns.branch')}</TableCell>
                <TableCell />
              </TableRow>
            </TableHead>
            <TableBody>
              {holidays.length === 0 ? (
                <TableRow><TableCell colSpan={4}><Typography variant="body2" color="text.secondary" sx={{ py: 2, textAlign: 'center' }}>{t('clinic.holidays.empty')}</Typography></TableCell></TableRow>
              ) : holidays.map((holiday) => (
                <TableRow key={holiday.id} hover>
                  <TableCell>{formatDateDhaka(holiday.holiday_date)}</TableCell>
                  <TableCell>
                    <Typography variant="body2">{locale === 'bn' && holiday.name_bn ? holiday.name_bn : holiday.name}</Typography>
                    {holiday.name_bn && locale !== 'bn' ? <Typography variant="caption" color="text.secondary" lang="bn">{holiday.name_bn}</Typography> : null}
                  </TableCell>
                  <TableCell>
                    {holiday.branch_id === null
                      ? <Chip size="small" label={t('clinic.holidays.clinic_wide')} />
                      : holiday.branch_name ?? '—'}
                  </TableCell>
                  <TableCell align="right">
                    {can.manage ? <IconButton size="small" aria-label={t('common.actions.delete')} onClick={() => remove(holiday)}><DeleteIcon fontSize="small" /></IconButton> : null}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Box>
      </Card>

      <Dialog open={open} onClose={() => setOpen(false)} fullWidth maxWidth="sm">
        <form onSubmit={(e) => { e.preventDefault(); form.post(route('panel.clinic.holidays.store'), { preserveScroll: true, onSuccess: () => setOpen(false) }); }}>
          <DialogTitle>{t('clinic.holidays.add')}</DialogTitle>
          <DialogContent>
            <Stack spacing={2} sx={{ pt: 1 }}>
              <TextField
                label={t('clinic.holidays.fields.date')} type="date" value={form.data.holiday_date} required fullWidth
                onChange={(e) => form.setData('holiday_date', e.target.value)}
                error={Boolean(form.errors.holiday_date)} helperText={form.errors.holiday_date}
                slotProps={{ inputLabel: { shrink: true } }}
              />
              <TextField
                label={t('clinic.holidays.fields.name')} value={form.data.name} required autoFocus fullWidth
                onChange={(e) => form.setData('name', e.target.value)}
                error={Boolean(form.errors.name)} helperText={form.errors.name}
                slotProps={{ htmlInput: { maxLength: 120 } }}
              />
              <TextField
                label={t('clinic.holidays.fields.name_bn')} value={form.data.name_bn} fullWidth
                onChange={(e) => form.setData('name_bn', e.target.value)}
                error={Boolean(form.errors.name_bn)} helperText={form.errors.name_bn}
                slotProps={{ htmlInput: { lang: 'bn', maxLength: 160 } }}
              />
              <TextField
                select label={t('clinic.holidays.fields.branch')} value={form.data.branch_id} fullWidth
                onChange={(e) => form.setData('branch_id', e.target.value)}
                helperText={t('clinic.holidays.fields.branch_help')}
              >
                <MenuItem value="">{t('clinic.holidays.clinic_wide')}</MenuItem>
                {branch_options.map((b) => <MenuItem key={b.public_id} value={String(b.id)}>{b.name}</MenuItem>)}
              </TextField>
              {domainError ? <Typography variant="body2" color="error">{domainError}</Typography> : null}
            </Stack>
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setOpen(false)}>{t('common.actions.cancel')}</Button>
            <Button type="submit" variant="contained" disabled={form.processing}>{t('common.actions.save')}</Button>
          </DialogActions>
        </form>
      </Dialog>
    </Stack>
  );
}

Index.layout = (page: ReactNode) => <PanelLayout title="clinic.holidays.title">{page}</PanelLayout>;
