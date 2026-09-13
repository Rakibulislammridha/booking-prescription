// One staff form for Create and Edit. A new account gets no password typed here — it is created with a random one
// and `must_change_password`, and the admin sends a reset link from the list. That is the only flow in which nobody
// but the account holder ever knows the password.
import { useTranslation } from 'react-i18next';
import type { InertiaFormProps } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import FormControlLabel from '@mui/material/FormControlLabel';
import Grid from '@mui/material/Grid';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import type { ClinicBranch } from '@shared/types/models';

export interface StaffFormData {
  name: string;
  email: string;
  mobile: string;
  role: string;
  default_branch_id: string;
  locale: 'bn' | 'en';
  is_active: boolean;
  must_change_password: boolean;
  session_timeout_minutes: string;
}

interface Props {
  form: InertiaFormProps<StaffFormData>;
  branches: ClinicBranch[];
  roles: string[];
  /** Editing your own account: the role select and the active switch are locked (UpdateStaffUser refuses both). */
  isSelf?: boolean;
  isAdminSelf?: boolean;
}

export function StaffForm({ form, branches, roles, isSelf = false, isAdminSelf = false }: Props) {
  const { t } = useTranslation();
  const { data, errors } = form;
  // Every other role is complete the moment the account is saved. A compounder is not: the role carries the four
  // permissions but no doctor, so until someone opens Setup → Doctors → Compounders the new account signs in to a
  // desk with nothing on it (DoctorScope answers "no doctors" and the board comes back empty). The generic
  // "roles carry the permissions" line is therefore a lie for exactly this one choice, and an admin who read it
  // would go looking for the bug in the wrong place — so the helper says what is still missing, and where.
  const roleHelp = data.role === 'compounder' ? 'clinic.staff.fields.role_help_compounder' : 'clinic.staff.fields.role_help';

  return (
    <Stack spacing={2}>
      {isSelf ? <Alert severity="info">{t('clinic.staff.self_notice')}</Alert> : null}
      <Card>
        <CardContent>
          <Grid container spacing={2}>
            <Grid size={{ xs: 12, sm: 6 }}>
              <TextField
                label={t('clinic.staff.fields.name')} value={data.name} required autoFocus fullWidth
                onChange={(e) => form.setData('name', e.target.value)}
                error={Boolean(errors.name)} helperText={errors.name}
                slotProps={{ htmlInput: { lang: 'bn', maxLength: 160 } }}
              />
            </Grid>
            <Grid size={{ xs: 12, sm: 6 }}>
              <TextField
                label={t('clinic.staff.fields.email')} value={data.email} type="email" required fullWidth
                onChange={(e) => form.setData('email', e.target.value)}
                error={Boolean(errors.email)} helperText={errors.email ?? t('clinic.staff.fields.email_help')}
              />
            </Grid>
            <Grid size={{ xs: 12, sm: 6 }}>
              <TextField
                label={t('clinic.staff.fields.mobile')} value={data.mobile} fullWidth
                onChange={(e) => form.setData('mobile', e.target.value)}
                error={Boolean(errors.mobile)} helperText={errors.mobile ?? t('clinic.staff.fields.mobile_help')}
                slotProps={{ htmlInput: { inputMode: 'tel', maxLength: 14, placeholder: '+8801XXXXXXXXX' } }}
              />
            </Grid>
            <Grid size={{ xs: 12, sm: 6 }}>
              <TextField
                select label={t('clinic.staff.fields.role')} value={data.role} required fullWidth
                disabled={isAdminSelf}
                onChange={(e) => form.setData('role', e.target.value)}
                error={Boolean(errors.role)}
                helperText={errors.role ?? (isAdminSelf ? t('clinic.staff.fields.role_self_locked') : t(roleHelp))}
              >
                {roles.map((role) => <MenuItem key={role} value={role}>{t(`roles.${role}`)}</MenuItem>)}
              </TextField>
            </Grid>
            <Grid size={{ xs: 12, sm: 6 }}>
              <TextField
                select label={t('clinic.staff.fields.branch')} value={data.default_branch_id} fullWidth
                onChange={(e) => form.setData('default_branch_id', e.target.value)}
                error={Boolean(errors.default_branch_id)} helperText={errors.default_branch_id ?? t('clinic.staff.fields.branch_help')}
              >
                <MenuItem value="">{t('common.status.none')}</MenuItem>
                {branches.map((branch) => <MenuItem key={branch.public_id} value={String(branch.id)}>{branch.name}</MenuItem>)}
              </TextField>
            </Grid>
            <Grid size={{ xs: 12, sm: 3 }}>
              <TextField
                select label={t('clinic.staff.fields.locale')} value={data.locale} fullWidth
                onChange={(e) => form.setData('locale', e.target.value === 'en' ? 'en' : 'bn')}
              >
                <MenuItem value="bn">বাংলা</MenuItem>
                <MenuItem value="en">English</MenuItem>
              </TextField>
            </Grid>
            <Grid size={{ xs: 12, sm: 3 }}>
              <TextField
                label={t('clinic.staff.fields.session_timeout')} type="number" value={data.session_timeout_minutes} fullWidth
                onChange={(e) => form.setData('session_timeout_minutes', e.target.value)}
                error={Boolean(errors.session_timeout_minutes)} helperText={errors.session_timeout_minutes ?? t('clinic.staff.fields.session_timeout_help')}
                slotProps={{ htmlInput: { min: 5, max: 1440, inputMode: 'numeric' } }}
              />
            </Grid>
          </Grid>
        </CardContent>
      </Card>

      <Card>
        <CardContent>
          <Stack spacing={0.5}>
            <FormControlLabel
              control={<Switch checked={data.is_active} disabled={isSelf} onChange={(e) => form.setData('is_active', e.target.checked)} />}
              label={t('clinic.staff.fields.is_active')}
            />
            <FormControlLabel
              control={<Switch checked={data.must_change_password} onChange={(e) => form.setData('must_change_password', e.target.checked)} />}
              label={t('clinic.staff.fields.must_change_password')}
            />
          </Stack>
        </CardContent>
      </Card>
    </Stack>
  );
}
