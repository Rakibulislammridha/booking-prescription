// Clinic/Departments/Index — Medicine, Surgery, Gynae… A clinic has a dozen of these, so the list is the editor:
// one dialog, no separate pages. Two names, exactly as specialties do (SCHEMA §3.1): `name` is the English one a
// profile or an export prints, `name_bn` is the Bangla one the desk and the public site read.
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
import MenuItem from '@mui/material/MenuItem';
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
import type { PageProps } from '@shared/types/inertia';
import type { ClinicBranch, ClinicDepartment } from '@shared/types/models';

type Props = PageProps<{
  departments: ClinicDepartment[];
  /** Named `branch_options` so the shared `branches` (the switcher list) stays readable on this page too. */
  branch_options: ClinicBranch[];
  can: { manage: boolean };
}>;

interface FormData {
  name: string;
  name_bn: string;
  slug: string;
  branch_id: string;
  sort_order: number;
  is_active: boolean;
}

export default function Index({ departments, branch_options, can }: Props) {
  const { t } = useTranslation();
  const [editing, setEditing] = useState<ClinicDepartment | null | undefined>(undefined);
  const open = editing !== undefined;

  const form = useForm<FormData>({ name: '', name_bn: '', slug: '', branch_id: '', sort_order: 0, is_active: true });

  const start = (department: ClinicDepartment | null): void => {
    form.setDefaults({
      name: department?.name ?? '',
      name_bn: department?.name_bn ?? '',
      slug: department?.slug ?? '',
      branch_id: department?.branch_id ? String(department.branch_id) : '',
      sort_order: department?.sort_order ?? 0,
      is_active: department?.is_active ?? true,
    });
    form.reset();
    form.clearErrors();
    setEditing(department);
  };
  const close = (): void => setEditing(undefined);
  const submit = (): void => {
    const options = { preserveScroll: true, onSuccess: close };
    if (editing) form.put(route('panel.clinic.departments.update', { department: editing.id }), options);
    else form.post(route('panel.clinic.departments.store'), options);
  };
  const remove = (department: ClinicDepartment): void => {
    if (window.confirm(t('clinic.departments.delete_confirm', { name: department.name }))) {
      router.delete(route('panel.clinic.departments.destroy', { department: department.id }), { preserveScroll: true });
    }
  };

  return (
    <Stack spacing={2}>
      <Stack direction="row" spacing={1.5} sx={{ alignItems: 'center' }}>
        <Typography variant="body2" color="text.secondary" sx={{ flexGrow: 1, maxWidth: 720 }}>{t('clinic.departments.intro')}</Typography>
        {can.manage ? <Button variant="contained" startIcon={<AddIcon />} onClick={() => start(null)}>{t('clinic.departments.add')}</Button> : null}
      </Stack>

      <Card>
        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small" aria-label={t('clinic.departments.title')}>
            <TableHead>
              <TableRow>
                <TableCell>{t('clinic.departments.columns.name')}</TableCell>
                <TableCell>{t('clinic.departments.columns.name_bn')}</TableCell>
                <TableCell>{t('clinic.departments.columns.branch')}</TableCell>
                <TableCell>{t('clinic.departments.columns.sort')}</TableCell>
                <TableCell>{t('clinic.departments.columns.active')}</TableCell>
                <TableCell />
              </TableRow>
            </TableHead>
            <TableBody>
              {departments.length === 0 ? (
                <TableRow><TableCell colSpan={6}><Typography variant="body2" color="text.secondary" sx={{ py: 2, textAlign: 'center' }}>{t('clinic.departments.empty')}</Typography></TableCell></TableRow>
              ) : departments.map((department) => (
                <TableRow key={department.id} hover>
                  <TableCell>
                    <Typography variant="body2" sx={{ fontWeight: 600 }}>{department.name}</Typography>
                    <Typography variant="caption" color="text.secondary" sx={{ fontFamily: 'monospace' }}>{department.slug}</Typography>
                  </TableCell>
                  <TableCell lang="bn">{department.name_bn ?? '—'}</TableCell>
                  <TableCell>{department.branch_name ?? t('clinic.departments.all_branches')}</TableCell>
                  <TableCell>{department.sort_order}</TableCell>
                  <TableCell>
                    {department.is_active ? <Chip size="small" color="success" label={t('clinic.status.active')} /> : <Chip size="small" label={t('clinic.status.inactive')} />}
                  </TableCell>
                  <TableCell align="right">
                    {can.manage ? (
                      <>
                        <Button size="small" onClick={() => start(department)}>{t('common.actions.edit')}</Button>
                        <IconButton size="small" aria-label={t('common.actions.delete')} onClick={() => remove(department)}><DeleteIcon fontSize="small" /></IconButton>
                      </>
                    ) : null}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Box>
      </Card>

      <Dialog open={open} onClose={close} fullWidth maxWidth="sm">
        <form onSubmit={(e) => { e.preventDefault(); submit(); }}>
          <DialogTitle>{editing ? t('clinic.departments.edit_title') : t('clinic.departments.add')}</DialogTitle>
          <DialogContent>
            <Stack spacing={2} sx={{ pt: 1 }}>
              <TextField
                label={t('clinic.departments.fields.name')} value={form.data.name} required autoFocus fullWidth
                onChange={(e) => form.setData('name', e.target.value)}
                error={Boolean(form.errors.name)} helperText={form.errors.name ?? t('clinic.departments.fields.name_help')}
                slotProps={{ htmlInput: { maxLength: 120 } }}
              />
              <TextField
                label={t('clinic.departments.fields.name_bn')} value={form.data.name_bn} fullWidth
                onChange={(e) => form.setData('name_bn', e.target.value)}
                error={Boolean(form.errors.name_bn)} helperText={form.errors.name_bn ?? t('clinic.departments.fields.name_bn_help')}
                slotProps={{ htmlInput: { lang: 'bn', maxLength: 160 } }}
              />
              <TextField
                label={t('clinic.departments.fields.slug')} value={form.data.slug} fullWidth
                onChange={(e) => form.setData('slug', e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''))}
                error={Boolean(form.errors.slug)} helperText={form.errors.slug ?? t('clinic.departments.fields.slug_help')}
              />
              <TextField
                select label={t('clinic.departments.fields.branch')} value={form.data.branch_id} fullWidth
                onChange={(e) => form.setData('branch_id', e.target.value)}
                error={Boolean(form.errors.branch_id)} helperText={form.errors.branch_id}
              >
                <MenuItem value="">{t('clinic.departments.all_branches')}</MenuItem>
                {branch_options.map((branch) => <MenuItem key={branch.public_id} value={String(branch.id)}>{branch.name}</MenuItem>)}
              </TextField>
              <TextField
                label={t('clinic.departments.fields.sort')} type="number" value={form.data.sort_order} fullWidth
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

Index.layout = (page: ReactNode) => <PanelLayout title="clinic.departments.title">{page}</PanelLayout>;
