// Clinic/Doctors/Create — two ways in, one screen: link an existing staff login, or create one alongside the doctor
// row. A visiting doctor with no login is also valid (SCHEMA §3.1: `doctors.user_id` is nullable) — reception then
// handles their serials.
import { useState, type ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Grid from '@mui/material/Grid';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import Typography from '@mui/material/Typography';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { DoctorForm, emptyDoctorForm, type DoctorFormData } from '@panel/Components/Clinic/DoctorForm';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type { ClinicBranch, ClinicDepartment, ClinicSpecialty, ClinicStaffUser } from '@shared/types/models';

type Props = PageProps<{
  departments: ClinicDepartment[];
  specialties: ClinicSpecialty[];
  users: ClinicStaffUser[];
  genders: string[];
  /** Named `branch_options`, not `branches`: SharedProps already owns `branches` (the switcher list). */
  branch_options: ClinicBranch[];
}>;

type LoginMode = 'none' | 'existing' | 'new';

export default function Create({ departments, specialties, users, genders, branch_options }: Props) {
  const { t } = useTranslation();
  const [mode, setMode] = useState<LoginMode>('none');
  const form = useForm<DoctorFormData>(emptyDoctorForm());

  const newUser = form.data.new_user;

  const changeMode = (next: LoginMode): void => {
    setMode(next);
    const main = branch_options.find((b) => b.is_main);
    form.setData((current) => ({
      ...current,
      user_id: next === 'existing' ? current.user_id : '',
      new_user: next === 'new'
        ? { name: current.name, email: current.email, mobile: current.mobile, default_branch_id: main ? String(main.id) : '', locale: 'bn' }
        : null,
    }));
  };

  return (
    <form onSubmit={(e) => { e.preventDefault(); form.post(route('panel.clinic.doctors.store')); }}>
      <Stack spacing={2} sx={{ maxWidth: 1000 }}>
        <Card>
          <CardContent>
            <Typography variant="subtitle2" gutterBottom>{t('clinic.doctors.login.title')}</Typography>
            <ToggleButtonGroup exclusive size="small" value={mode} onChange={(_, value) => { if (value) changeMode(value as LoginMode); }} aria-label={t('clinic.doctors.login.title')}>
              <ToggleButton value="none">{t('clinic.doctors.login.none')}</ToggleButton>
              <ToggleButton value="existing">{t('clinic.doctors.login.existing')}</ToggleButton>
              <ToggleButton value="new">{t('clinic.doctors.login.new')}</ToggleButton>
            </ToggleButtonGroup>
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>{t(`clinic.doctors.login.help_${mode}`)}</Typography>

            {mode === 'existing' ? (
              <TextField
                select label={t('clinic.doctors.login.pick_user')} value={form.data.user_id} fullWidth sx={{ mt: 2 }}
                onChange={(e) => form.setData('user_id', e.target.value)}
                error={Boolean(form.errors.user_id)} helperText={form.errors.user_id}
              >
                <MenuItem value="">{t('common.status.none')}</MenuItem>
                {users.map((u) => <MenuItem key={u.public_id} value={String(u.id)}>{u.name} — {u.email}</MenuItem>)}
              </TextField>
            ) : null}

            {mode === 'new' && newUser ? (
              <Grid container spacing={2} sx={{ mt: 0 }}>
                <Grid size={{ xs: 12, sm: 6 }}>
                  <TextField
                    label={t('clinic.staff.fields.name')} value={newUser.name} fullWidth required
                    onChange={(e) => form.setData('new_user', { ...newUser, name: e.target.value })}
                    error={Boolean(form.errors['new_user.name' as keyof typeof form.errors])}
                    slotProps={{ htmlInput: { lang: 'bn' } }}
                  />
                </Grid>
                <Grid size={{ xs: 12, sm: 6 }}>
                  <TextField
                    label={t('clinic.staff.fields.email')} value={newUser.email} type="email" fullWidth required
                    onChange={(e) => form.setData('new_user', { ...newUser, email: e.target.value })}
                    error={Boolean(form.errors['new_user.email' as keyof typeof form.errors])}
                    helperText={(form.errors as Record<string, string | undefined>)['new_user.email']}
                  />
                </Grid>
                <Grid size={{ xs: 12, sm: 6 }}>
                  <TextField
                    label={t('clinic.staff.fields.mobile')} value={newUser.mobile} fullWidth
                    onChange={(e) => form.setData('new_user', { ...newUser, mobile: e.target.value })}
                    helperText={(form.errors as Record<string, string | undefined>)['new_user.mobile'] ?? t('clinic.staff.fields.mobile_help')}
                    slotProps={{ htmlInput: { inputMode: 'tel', placeholder: '+8801XXXXXXXXX' } }}
                  />
                </Grid>
                <Grid size={{ xs: 12, sm: 6 }}>
                  <TextField
                    select label={t('clinic.staff.fields.branch')} value={newUser.default_branch_id} fullWidth
                    onChange={(e) => form.setData('new_user', { ...newUser, default_branch_id: e.target.value })}
                  >
                    <MenuItem value="">{t('common.status.none')}</MenuItem>
                    {branch_options.map((b) => <MenuItem key={b.public_id} value={String(b.id)}>{b.name}</MenuItem>)}
                  </TextField>
                </Grid>
              </Grid>
            ) : null}
          </CardContent>
        </Card>

        <DoctorForm form={form} departments={departments} specialties={specialties} genders={genders} />

        <Stack direction="row" spacing={1}>
          <Button type="submit" variant="contained" disabled={form.processing}>{t('common.actions.save')}</Button>
          <Button component={RouterLink} href={route('panel.clinic.doctors.index')}>{t('common.actions.cancel')}</Button>
        </Stack>
      </Stack>
    </form>
  );
}

Create.layout = (page: ReactNode) => <PanelLayout title="clinic.doctors.create_title">{page}</PanelLayout>;
