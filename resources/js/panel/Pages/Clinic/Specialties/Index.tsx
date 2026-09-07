// Clinic/Specialties/Index — Cardiology / হৃদরোগ. Both names matter: the English one is what a doctor's profile
// prints, the Bangla one is what a patient searches for on the public booking site.
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
import FormControlLabel from '@mui/material/FormControlLabel';
import IconButton from '@mui/material/IconButton';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
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
import { route } from '@shared/routes';
import { formatBn } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import type { PageProps } from '@shared/types/inertia';
import type { ClinicSpecialty } from '@shared/types/models';

type Props = PageProps<{ specialties: ClinicSpecialty[]; can: { manage: boolean } }>;

interface FormData {
  name: string;
  name_bn: string;
  slug: string;
  icon: string;
  sort_order: number;
  is_active: boolean;
}

export default function Index({ specialties, can }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [editing, setEditing] = useState<ClinicSpecialty | null | undefined>(undefined);
  const form = useForm<FormData>({ name: '', name_bn: '', slug: '', icon: '', sort_order: 0, is_active: true });

  const start = (specialty: ClinicSpecialty | null): void => {
    form.setDefaults({
      name: specialty?.name ?? '',
      name_bn: specialty?.name_bn ?? '',
      slug: specialty?.slug ?? '',
      icon: specialty?.icon ?? '',
      sort_order: specialty?.sort_order ?? 0,
      is_active: specialty?.is_active ?? true,
    });
    form.reset();
    form.clearErrors();
    setEditing(specialty);
  };
  const close = (): void => setEditing(undefined);
  const submit = (): void => {
    const options = { preserveScroll: true, onSuccess: close };
    if (editing) form.put(route('panel.clinic.specialties.update', { specialty: editing.id }), options);
    else form.post(route('panel.clinic.specialties.store'), options);
  };
  const remove = (specialty: ClinicSpecialty): void => {
    if (window.confirm(t('clinic.specialties.delete_confirm', { name: specialty.name }))) {
      router.delete(route('panel.clinic.specialties.destroy', { specialty: specialty.id }), { preserveScroll: true });
    }
  };

  return (
    <Stack spacing={2}>
      <Stack direction="row" spacing={1.5} sx={{ alignItems: 'center' }}>
        <Typography variant="body2" color="text.secondary" sx={{ flexGrow: 1, maxWidth: 720 }}>{t('clinic.specialties.intro')}</Typography>
        {can.manage ? <Button variant="contained" startIcon={<AddIcon />} onClick={() => start(null)}>{t('clinic.specialties.add')}</Button> : null}
      </Stack>

      <Card>
        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small" aria-label={t('clinic.specialties.title')}>
            <TableHead>
              <TableRow>
                <TableCell>{t('clinic.specialties.columns.name')}</TableCell>
                <TableCell>{t('clinic.specialties.columns.name_bn')}</TableCell>
                <TableCell>{t('clinic.specialties.columns.doctors')}</TableCell>
                <TableCell>{t('clinic.specialties.columns.active')}</TableCell>
                <TableCell />
              </TableRow>
            </TableHead>
            <TableBody>
              {specialties.length === 0 ? (
                <TableRow><TableCell colSpan={5}><Typography variant="body2" color="text.secondary" sx={{ py: 2, textAlign: 'center' }}>{t('clinic.specialties.empty')}</Typography></TableCell></TableRow>
              ) : specialties.map((specialty) => (
                <TableRow key={specialty.id} hover>
                  <TableCell>
                    <Typography variant="body2" sx={{ fontWeight: 600 }}>{specialty.name}</Typography>
                    <Typography variant="caption" color="text.secondary" sx={{ fontFamily: 'monospace' }}>{specialty.slug}</Typography>
                  </TableCell>
                  <TableCell lang="bn">{specialty.name_bn ?? '—'}</TableCell>
                  <TableCell>{formatBn(specialty.doctors_count ?? 0, locale)}</TableCell>
                  <TableCell>
                    {specialty.is_active ? <Chip size="small" color="success" label={t('clinic.status.active')} /> : <Chip size="small" label={t('clinic.status.inactive')} />}
                  </TableCell>
                  <TableCell align="right">
                    {can.manage ? (
                      <>
                        <Button size="small" onClick={() => start(specialty)}>{t('common.actions.edit')}</Button>
                        <IconButton size="small" aria-label={t('common.actions.delete')} onClick={() => remove(specialty)}><DeleteIcon fontSize="small" /></IconButton>
                      </>
                    ) : null}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Box>
      </Card>

      <Dialog open={editing !== undefined} onClose={close} fullWidth maxWidth="sm">
        <form onSubmit={(e) => { e.preventDefault(); submit(); }}>
          <DialogTitle>{editing ? t('clinic.specialties.edit_title') : t('clinic.specialties.add')}</DialogTitle>
          <DialogContent>
            <Stack spacing={2} sx={{ pt: 1 }}>
              <TextField
                label={t('clinic.specialties.fields.name')} value={form.data.name} required autoFocus fullWidth
                onChange={(e) => form.setData('name', e.target.value)}
                error={Boolean(form.errors.name)} helperText={form.errors.name}
                slotProps={{ htmlInput: { maxLength: 120 } }}
              />
              <TextField
                label={t('clinic.specialties.fields.name_bn')} value={form.data.name_bn} fullWidth
                onChange={(e) => form.setData('name_bn', e.target.value)}
                error={Boolean(form.errors.name_bn)} helperText={form.errors.name_bn ?? t('clinic.specialties.fields.name_bn_help')}
                slotProps={{ htmlInput: { lang: 'bn', maxLength: 160 } }}
              />
              <TextField
                label={t('clinic.specialties.fields.slug')} value={form.data.slug} fullWidth
                onChange={(e) => form.setData('slug', e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''))}
                error={Boolean(form.errors.slug)} helperText={form.errors.slug}
              />
              <TextField
                label={t('clinic.specialties.fields.icon')} value={form.data.icon} fullWidth
                onChange={(e) => form.setData('icon', e.target.value)}
                error={Boolean(form.errors.icon)} helperText={form.errors.icon ?? t('clinic.specialties.fields.icon_help')}
              />
              <TextField
                label={t('clinic.specialties.fields.sort')} type="number" value={form.data.sort_order} fullWidth
                onChange={(e) => form.setData('sort_order', Number(e.target.value))}
                slotProps={{ htmlInput: { min: 0, max: 32767, inputMode: 'numeric' } }}
              />
              <FormControlLabel control={<Switch checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />} label={t('clinic.status.active')} />
            </Stack>
          </DialogContent>
          <DialogActions>
            <Button onClick={close}>{t('common.actions.cancel')}</Button>
            <Button type="submit" variant="contained" disabled={form.processing}>{t('common.actions.save')}</Button>
          </DialogActions>
        </form>
      </Dialog>
    </Stack>
  );
}

Index.layout = (page: ReactNode) => <PanelLayout title="clinic.specialties.title">{page}</PanelLayout>;
