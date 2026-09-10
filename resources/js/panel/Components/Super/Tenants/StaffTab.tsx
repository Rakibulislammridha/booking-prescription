// A clinic's staff as support sees them, and the four things support does to them: sign in as one, create a
// hospital admin, hand out a way back in, switch an account off. Every action is a dialog that says what it does;
// "log in as" is a plain form post because the answer is a cross-host redirect only a real navigation can follow.
import { useState, type FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogContentText from '@mui/material/DialogContentText';
import DialogTitle from '@mui/material/DialogTitle';
import FormControl from '@mui/material/FormControl';
import FormControlLabel from '@mui/material/FormControlLabel';
import FormHelperText from '@mui/material/FormHelperText';
import FormLabel from '@mui/material/FormLabel';
import MenuItem from '@mui/material/MenuItem';
import Radio from '@mui/material/Radio';
import RadioGroup from '@mui/material/RadioGroup';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import LoginIcon from '@mui/icons-material/Login';
import PersonAddAlt1Icon from '@mui/icons-material/PersonAddAlt1';
import { SuperTable, type SuperColumn } from '@panel/Components/Super/SuperTable';
import { useSharedProps } from '@shared/inertia';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { route } from '@shared/routes';
import { CredentialChoice } from './CredentialChoice';
import type { ConsoleTenantDetail, CredentialKind, StaffRow } from './types';

export interface StaffTabProps {
  tenant: ConsoleTenantDetail;
  staff: StaffRow[];
  roles: string[];
}

interface StaffForm {
  name: string;
  email: string;
  mobile: string;
  role: string;
  locale: 'bn' | 'en';
  credential: CredentialKind;
  password: string;
}

function AddStaffDialog({ open, tenant, roles, onClose }: { open: boolean; tenant: ConsoleTenantDetail; roles: string[]; onClose: () => void }) {
  const { t } = useTranslation();
  const shared = useSharedProps();
  const form = useForm<StaffForm>({ name: '', email: '', mobile: '', role: 'hospital_admin', locale: tenant.locale === 'en' ? 'en' : 'bn', credential: 'link', password: '' });

  const submit = (event: FormEvent): void => {
    event.preventDefault();
    form.post(route('super.tenants.staff.store', { tenant: tenant.public_id }), {
      preserveScroll: true,
      onSuccess: () => { form.reset(); onClose(); },
    });
  };

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
      <Box component="form" onSubmit={submit} noValidate data-testid="add-staff-form">
        <DialogTitle>{t('super.tenants.staff.add_title')}</DialogTitle>
        <DialogContent sx={{ display: 'grid', gap: 2, pt: 1 }}>
          <TextField label={t('super.tenants.staff.form.name')} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} error={Boolean(form.errors.name)} helperText={form.errors.name} required autoFocus slotProps={{ htmlInput: { maxLength: 160, lang: 'bn', 'data-testid': 'staff-name' } }} />
          <TextField label={t('super.tenants.staff.form.email')} type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} error={Boolean(form.errors.email)} helperText={form.errors.email} required slotProps={{ htmlInput: { maxLength: 255, 'data-testid': 'staff-email' } }} />
          <TextField label={t('super.tenants.staff.form.mobile')} value={form.data.mobile} onChange={(e) => form.setData('mobile', e.target.value)} error={Boolean(form.errors.mobile)} helperText={form.errors.mobile ?? t('super.tenants.form.mobile_help')} slotProps={{ htmlInput: { maxLength: 20, inputMode: 'tel' } }} />
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
            <TextField select label={t('super.tenants.staff.form.role')} value={form.data.role} onChange={(e) => form.setData('role', e.target.value)} error={Boolean(form.errors.role)} helperText={form.errors.role} fullWidth>
              {roles.map((role) => <MenuItem key={role} value={role}>{t(`roles.${role}`, { defaultValue: role })}</MenuItem>)}
            </TextField>
            <TextField select label={t('super.tenants.form.locale')} value={form.data.locale} onChange={(e) => form.setData('locale', e.target.value as 'bn' | 'en')} fullWidth>
              <MenuItem value="bn">বাংলা</MenuItem>
              <MenuItem value="en">English</MenuItem>
            </TextField>
          </Stack>
          <CredentialChoice kind={form.data.credential} onKind={(kind) => form.setData('credential', kind)} password={form.data.password} onPassword={(p) => form.setData('password', p)} error={form.errors.password} />
          {shared.errors.domain ? <Alert severity="error">{shared.errors.domain}</Alert> : null}
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose}>{t('super.actions.cancel')}</Button>
          <Button type="submit" variant="contained" disabled={form.processing || form.data.name.trim() === '' || form.data.email.trim() === ''} data-testid="staff-submit">
            {t('super.tenants.staff.add')}
          </Button>
        </DialogActions>
      </Box>
    </Dialog>
  );
}

function ResetPasswordDialog({ tenant, user, onClose }: { tenant: ConsoleTenantDetail; user: StaffRow | null; onClose: () => void }) {
  const { t } = useTranslation();
  const shared = useSharedProps();
  const form = useForm<{ mode: CredentialKind }>({ mode: 'link' });

  const submit = (event: FormEvent): void => {
    event.preventDefault();
    if (user === null) return;
    form.post(route('super.tenants.staff.password', { tenant: tenant.public_id, user: user.public_id }), { preserveScroll: true, onSuccess: onClose });
  };

  return (
    <Dialog open={user !== null} onClose={onClose} fullWidth maxWidth="sm">
      <Box component="form" onSubmit={submit} noValidate>
        <DialogTitle>{t('super.tenants.staff.reset_title')}</DialogTitle>
        <DialogContent sx={{ display: 'grid', gap: 2, pt: 1 }}>
          <DialogContentText>{t('super.tenants.staff.reset_body', { name: user?.name ?? '', email: user?.email ?? '' })}</DialogContentText>
          <FormControl>
            <FormLabel id="reset-mode">{t('super.tenants.form.credential')}</FormLabel>
            <RadioGroup aria-labelledby="reset-mode" value={form.data.mode} onChange={(e) => form.setData('mode', e.target.value as CredentialKind)}>
              <FormControlLabel value="link" control={<Radio />} label={t('super.tenants.staff.reset_mode_link')} />
              <FormHelperText sx={{ mt: -0.5, ml: 4 }}>{t('super.tenants.staff.reset_mode_link_help')}</FormHelperText>
              <FormControlLabel value="password" control={<Radio />} label={t('super.tenants.staff.reset_mode_password')} />
              <FormHelperText sx={{ mt: -0.5, ml: 4 }}>{t('super.tenants.staff.reset_mode_password_help')}</FormHelperText>
            </RadioGroup>
          </FormControl>
          {shared.errors.domain ? <Alert severity="error">{shared.errors.domain}</Alert> : null}
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose}>{t('super.actions.cancel')}</Button>
          <Button type="submit" variant="contained" color="warning" disabled={form.processing}>{t('super.tenants.staff.reset')}</Button>
        </DialogActions>
      </Box>
    </Dialog>
  );
}

function StatusDialog({ tenant, user, lastAdmin, onClose }: { tenant: ConsoleTenantDetail; user: StaffRow | null; lastAdmin: boolean; onClose: () => void }) {
  const { t } = useTranslation();
  const [processing, setProcessing] = useState(false);
  const deactivating = user?.is_active ?? false;

  const submit = (event: FormEvent): void => {
    event.preventDefault();
    if (user === null) return;
    setProcessing(true);
    router.post(route('super.tenants.staff.status', { tenant: tenant.public_id, user: user.public_id }), { is_active: !user.is_active }, {
      preserveScroll: true,
      onFinish: () => setProcessing(false),
      onSuccess: onClose,
    });
  };

  return (
    <Dialog open={user !== null} onClose={onClose} fullWidth maxWidth="sm">
      <Box component="form" onSubmit={submit} noValidate>
        <DialogTitle>{t(deactivating ? 'super.tenants.staff.deactivate_title' : 'super.tenants.staff.activate_title')}</DialogTitle>
        <DialogContent sx={{ display: 'grid', gap: 2, pt: 1 }}>
          <DialogContentText>{t(deactivating ? 'super.tenants.staff.deactivate_body' : 'super.tenants.staff.activate_body', { name: user?.name ?? '' })}</DialogContentText>
          {deactivating && lastAdmin ? <Alert severity="warning">{t('super.tenants.staff.last_admin_warning')}</Alert> : null}
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose}>{t('super.actions.cancel')}</Button>
          <Button type="submit" variant="contained" color={deactivating ? 'error' : 'success'} disabled={processing} data-testid="staff-status-confirm">
            {t(deactivating ? 'super.tenants.staff.deactivate' : 'super.tenants.staff.activate')}
          </Button>
        </DialogActions>
      </Box>
    </Dialog>
  );
}

export function StaffTab({ tenant, staff, roles }: StaffTabProps) {
  const { t } = useTranslation();
  const shared = useSharedProps();
  const locale = getLocale();
  const [addOpen, setAddOpen] = useState(false);
  const [resetUser, setResetUser] = useState<StaffRow | null>(null);
  const [statusUser, setStatusUser] = useState<StaffRow | null>(null);
  const activeAdmins = staff.filter((row) => row.is_active && row.role === 'hospital_admin').length;
  const canEnter = tenant.status !== 'cancelled';

  const columns: SuperColumn<StaffRow>[] = [
    {
      key: 'user',
      label: t('super.tenants.staff.column.name'),
      bn: true,
      render: (row) => (
        <Box>
          <Typography variant="body2" sx={{ fontWeight: 600 }}>{row.name}</Typography>
          <Typography variant="caption" color="text.secondary">{row.email}{row.mobile ? ` · ${row.mobile}` : ''}</Typography>
        </Box>
      ),
    },
    { key: 'role', label: t('super.tenants.staff.column.role'), render: (row) => (row.role ? t(`roles.${row.role}`, { defaultValue: row.role }) : '—') },
    { key: 'branch', label: t('super.tenants.staff.column.branch'), bn: true, render: (row) => row.branch_name ?? '—' },
    {
      key: 'status',
      label: t('super.tenants.staff.column.status'),
      render: (row) => (
        <Stack direction="row" spacing={0.5} sx={{ flexWrap: 'wrap' }} useFlexGap>
          <Chip size="small" color={row.is_active ? 'success' : 'default'} variant={row.is_active ? 'filled' : 'outlined'} label={t(row.is_active ? 'super.tenants.staff.active' : 'super.tenants.staff.inactive')} />
          {row.must_change_password ? <Chip size="small" color="warning" variant="outlined" label={t('super.tenants.staff.must_change')} /> : null}
        </Stack>
      ),
    },
    {
      key: 'last_login',
      label: t('super.tenants.staff.column.last_login'),
      render: (row) => (row.last_login_at ? (
        <Box>
          <div>{formatDhaka(row.last_login_at, 'D MMM YYYY, h:mm a', locale)}</div>
          {row.last_login_ip ? <Typography variant="caption" color="text.secondary" sx={{ fontFamily: 'monospace' }}>{row.last_login_ip}</Typography> : null}
        </Box>
      ) : <Typography variant="caption" color="text.secondary">{t('super.tenants.staff.never_logged_in')}</Typography>),
    },
    {
      key: 'actions',
      label: t('super.tenants.staff.column.actions'),
      align: 'right',
      render: (row) => (
        <Stack direction="row" spacing={0.5} sx={{ justifyContent: 'flex-end', flexWrap: 'wrap' }} useFlexGap>
          <Box component="form" method="post" action={route('super.tenants.impersonate', { tenant: tenant.public_id })} sx={{ display: 'inline' }}>
            <input type="hidden" name="_token" value={shared.csrf_token} />
            <input type="hidden" name="user" value={row.public_id} />
            <Button size="small" type="submit" startIcon={<LoginIcon />} disabled={!row.is_active || !canEnter} data-testid={`login-as-${row.public_id}`}>
              {t('super.tenants.staff.login_as')}
            </Button>
          </Box>
          <Button size="small" onClick={() => setResetUser(row)}>{t('super.tenants.staff.reset')}</Button>
          <Button size="small" color={row.is_active ? 'error' : 'success'} onClick={() => setStatusUser(row)} data-testid={`status-${row.public_id}`}>
            {t(row.is_active ? 'super.tenants.staff.deactivate' : 'super.tenants.staff.activate')}
          </Button>
        </Stack>
      ),
    },
  ];

  return (
    <Card variant="outlined">
      <CardContent>
        <Stack direction="row" spacing={1} sx={{ alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap' }} useFlexGap>
          <Box>
            <Typography variant="subtitle1" component="h3">{t('super.tenants.staff.title')}</Typography>
            <Typography variant="caption" color="text.secondary">{t('super.tenants.staff.subtitle', { count: staff.length })}</Typography>
          </Box>
          <Button variant="contained" startIcon={<PersonAddAlt1Icon />} onClick={() => setAddOpen(true)} data-testid="add-staff">
            {t('super.tenants.staff.add')}
          </Button>
        </Stack>
      </CardContent>
      <SuperTable columns={columns} rows={staff} rowKey={(row) => row.public_id} empty={t('super.tenants.staff.empty')} label={t('super.tenants.staff.title')} />

      <AddStaffDialog open={addOpen} tenant={tenant} roles={roles} onClose={() => setAddOpen(false)} />
      <ResetPasswordDialog tenant={tenant} user={resetUser} onClose={() => setResetUser(null)} />
      <StatusDialog tenant={tenant} user={statusUser} lastAdmin={statusUser?.role === 'hospital_admin' && activeAdmins <= 1} onClose={() => setStatusUser(null)} />
    </Card>
  );
}
