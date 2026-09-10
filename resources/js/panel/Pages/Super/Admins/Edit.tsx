// Super/Admins/Edit — one operator's account: identity, a password set by a colleague, and the access controls
// (deactivate/reactivate, reset the second factor, a set-password link, delete). The destructive ones live in
// dialogs that say what will happen; the credential-grade ones re-ask the acting operator's own password.
import { useState, type FormEvent, type ReactNode } from 'react';
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
import Divider from '@mui/material/Divider';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import type { SuperAdminDetail } from '@panel/Components/Super/types';
import { useSharedProps } from '@shared/inertia';
import { formatNumber } from '@shared/format/number';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import { TwoFactorChip } from './Index';

type Props = PageProps<{ admin: SuperAdminDetail }>;

interface EditForm {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  current_password: string;
}

/** A label above its value — the console's whole layout vocabulary for "here is a fact". */
function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <Box sx={{ minWidth: 0 }}>
      <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{label}</Typography>
      <Typography variant="body2" component="div" sx={{ wordBreak: 'break-word' }}>{children}</Typography>
    </Box>
  );
}

/** Reset 2FA and Delete both re-ask the acting operator's password; the same dialog serves both. */
function PasswordDialog({ open, title, body, submitLabel, color, onClose, onSubmit, processing, error }: {
  open: boolean;
  title: string;
  body: string;
  submitLabel: string;
  color: 'error' | 'warning';
  onClose: () => void;
  onSubmit: (password: string) => void;
  processing: boolean;
  error?: string;
}) {
  const { t } = useTranslation();
  const [password, setPassword] = useState('');

  const submit = (e: FormEvent<HTMLFormElement>): void => {
    e.preventDefault();
    onSubmit(password);
  };

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
      <Box component="form" onSubmit={submit} noValidate>
        <DialogTitle>{title}</DialogTitle>
        <DialogContent sx={{ display: 'grid', gap: 2, pt: 1 }}>
          <DialogContentText>{body}</DialogContentText>
          <TextField
            label={t('super.admins.field.current_password')} name="password" type="password" size="small" autoFocus autoComplete="current-password"
            value={password} onChange={(e) => setPassword(e.target.value)} error={Boolean(error)} helperText={error}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose}>{t('super.actions.cancel')}</Button>
          <Button type="submit" variant="contained" color={color} disabled={processing || password === ''}>{submitLabel}</Button>
        </DialogActions>
      </Box>
    </Dialog>
  );
}

export default function Edit({ admin }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const shared = useSharedProps();
  const form = useForm<EditForm>({ name: admin.name, email: admin.email, password: '', password_confirmation: '', current_password: '' });
  const resetForm = useForm({ password: '' });
  const deleteForm = useForm({ password: '' });
  const [dialog, setDialog] = useState<'deactivate' | 'reset' | 'delete' | null>(null);
  const domainError = shared.errors.domain;

  const save = (e: FormEvent<HTMLFormElement>): void => {
    e.preventDefault();
    form.put(route('super.admins.update', { admin: admin.id }), { preserveScroll: true, onFinish: () => form.reset('password', 'password_confirmation', 'current_password') });
  };
  const deactivate = (): void => {
    setDialog(null);
    router.post(route('super.admins.deactivate', { admin: admin.id }), {}, { preserveScroll: true });
  };
  const reactivate = (): void => { router.post(route('super.admins.reactivate', { admin: admin.id }), {}, { preserveScroll: true }); };
  const sendLink = (): void => { router.post(route('super.admins.password-link', { admin: admin.id }), {}, { preserveScroll: true }); };
  const resetTwoFactor = (password: string): void => {
    resetForm.transform(() => ({ password }));
    resetForm.post(route('super.admins.two-factor.reset', { admin: admin.id }), { preserveScroll: true, onSuccess: () => setDialog(null) });
  };
  const destroy = (password: string): void => {
    deleteForm.transform(() => ({ password }));
    deleteForm.delete(route('super.admins.destroy', { admin: admin.id }), { preserveScroll: true, onSuccess: () => setDialog(null) });
  };

  const typingPassword = form.data.password !== '';

  return (
    <Box>
      <Stack spacing={2} sx={{ maxWidth: 880 }}>
        <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
          <Button size="small" startIcon={<ArrowBackIcon />} component={RouterLink} href={route('super.admins.index')}>{t('super.admins.back')}</Button>
          <Typography variant="h6" component="h2" lang="bn" sx={{ flexGrow: 1 }}>{admin.name}</Typography>
          <Chip size="small" color={admin.is_active ? 'success' : 'default'} label={t(admin.is_active ? 'super.admins.active' : 'super.admins.inactive')} />
          <TwoFactorChip state={admin.two_factor} />
          {admin.is_self ? <Chip size="small" color="primary" variant="outlined" label={t('super.admins.you')} /> : null}
        </Stack>

        {domainError ? <Alert severity="error">{domainError}</Alert> : null}
        {admin.is_self ? <Alert severity="info">{t('super.admins.self_note')}</Alert> : null}

        <Card variant="outlined">
          <CardContent>
            <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: 'repeat(2, 1fr)', md: 'repeat(4, 1fr)' } }}>
              <Field label={t('super.admins.column.last_login')}>
                {admin.last_login_at ? formatDhaka(admin.last_login_at, 'D MMM YYYY, h:mm a', locale) : t('super.admins.never')}
                {admin.last_login_ip ? <Typography variant="caption" color="text.secondary" component="div" sx={{ fontFamily: 'monospace' }}>{admin.last_login_ip}</Typography> : null}
              </Field>
              <Field label={t('super.admins.column.created')}>{admin.created_at ? formatDhaka(admin.created_at, 'D MMM YYYY', locale) : '—'}</Field>
              <Field label={t('super.admins.column.two_factor')}>
                {t(`super.admins.two_factor.${admin.two_factor}`, { defaultValue: admin.two_factor })}
                {admin.two_factor === 'enabled' ? (
                  <Typography variant="caption" color="text.secondary" component="div">
                    {t('super.admins.recovery_codes', { count: formatNumber(admin.recovery_codes, locale) })}
                  </Typography>
                ) : null}
              </Field>
              <Field label={t('super.admins.column.usage')}>{t(admin.never_used ? 'super.admins.never_used' : 'super.admins.in_use')}</Field>
            </Box>
          </CardContent>
        </Card>

        <Card variant="outlined">
          <CardContent>
            <Typography variant="subtitle1" component="h3" sx={{ mb: 1.5 }}>{t('super.admins.identity_title')}</Typography>
            <Box component="form" onSubmit={save} noValidate sx={{ display: 'grid', gap: 2, maxWidth: 480 }}>
              <TextField
                label={t('super.admins.field.name')} name="name" size="small" required
                value={form.data.name} onChange={(e) => form.setData('name', e.target.value)}
                error={Boolean(form.errors.name)} helperText={form.errors.name}
              />
              <TextField
                label={t('super.admins.field.email')} name="email" type="email" size="small" required
                value={form.data.email} onChange={(e) => form.setData('email', e.target.value)}
                error={Boolean(form.errors.email)} helperText={form.errors.email}
              />
              <Divider />
              <Typography variant="body2" color="text.secondary">{t('super.admins.set_password_help')}</Typography>
              <TextField
                label={t('super.admins.field.new_password')} name="password" type="password" size="small" autoComplete="new-password"
                value={form.data.password} onChange={(e) => form.setData('password', e.target.value)}
                error={Boolean(form.errors.password)} helperText={form.errors.password}
              />
              {typingPassword ? (
                <>
                  <TextField
                    label={t('auth.confirm_password')} name="password_confirmation" type="password" size="small" autoComplete="new-password"
                    value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)}
                    error={Boolean(form.errors.password_confirmation)} helperText={form.errors.password_confirmation}
                  />
                  <TextField
                    label={t('super.admins.field.current_password')} name="current_password" type="password" size="small" autoComplete="current-password"
                    value={form.data.current_password} onChange={(e) => form.setData('current_password', e.target.value)}
                    error={Boolean(form.errors.current_password)} helperText={form.errors.current_password ?? t('super.admins.field.current_password_help')}
                  />
                </>
              ) : null}
              <Box><Button type="submit" variant="contained" disabled={form.processing || !form.isDirty}>{t('super.actions.save')}</Button></Box>
            </Box>
          </CardContent>
        </Card>

        <Card variant="outlined">
          <CardContent>
            <Typography variant="subtitle1" component="h3" sx={{ mb: 0.5 }}>{t('super.admins.access_title')}</Typography>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>{t('super.admins.access_help')}</Typography>
            <Stack spacing={2}>
              <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ alignItems: { sm: 'center' } }}>
                <Box sx={{ flexGrow: 1 }}>
                  <Typography variant="body2" sx={{ fontWeight: 600 }}>{t('super.admins.link_title')}</Typography>
                  <Typography variant="caption" color="text.secondary">{t('super.admins.link_help')}</Typography>
                </Box>
                <Button variant="outlined" size="small" onClick={sendLink}>{t('super.admins.link_send')}</Button>
              </Stack>
              <Divider />
              <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ alignItems: { sm: 'center' } }}>
                <Box sx={{ flexGrow: 1 }}>
                  <Typography variant="body2" sx={{ fontWeight: 600 }}>{t('super.admins.reset_title')}</Typography>
                  <Typography variant="caption" color="text.secondary">{t('super.admins.reset_help')}</Typography>
                </Box>
                <Button variant="outlined" color="warning" size="small" disabled={admin.is_self || admin.two_factor === 'none'} onClick={() => setDialog('reset')}>
                  {t('super.admins.reset_button')}
                </Button>
              </Stack>
              <Divider />
              <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ alignItems: { sm: 'center' } }}>
                <Box sx={{ flexGrow: 1 }}>
                  <Typography variant="body2" sx={{ fontWeight: 600 }}>{t(admin.is_active ? 'super.admins.deactivate_title' : 'super.admins.reactivate_title')}</Typography>
                  <Typography variant="caption" color="text.secondary">
                    {t(admin.is_active ? (admin.is_last_active ? 'super.admins.deactivate_last' : 'super.admins.deactivate_help') : 'super.admins.reactivate_help')}
                  </Typography>
                </Box>
                {admin.is_active ? (
                  <Button variant="outlined" color="error" size="small" disabled={admin.is_self || admin.is_last_active} onClick={() => setDialog('deactivate')}>
                    {t('super.admins.deactivate_button')}
                  </Button>
                ) : (
                  <Button variant="outlined" color="success" size="small" onClick={reactivate}>{t('super.admins.reactivate_button')}</Button>
                )}
              </Stack>
              <Divider />
              <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ alignItems: { sm: 'center' } }}>
                <Box sx={{ flexGrow: 1 }}>
                  <Typography variant="body2" sx={{ fontWeight: 600 }}>{t('super.admins.delete_title')}</Typography>
                  <Typography variant="caption" color="text.secondary">{t(admin.never_used ? 'super.admins.delete_help' : 'super.admins.delete_in_use')}</Typography>
                </Box>
                <Button variant="outlined" color="error" size="small" disabled={admin.is_self || !admin.never_used || admin.is_last_active} onClick={() => setDialog('delete')}>
                  {t('super.admins.delete_button')}
                </Button>
              </Stack>
            </Stack>
          </CardContent>
        </Card>
      </Stack>

      <Dialog open={dialog === 'deactivate'} onClose={() => setDialog(null)} fullWidth maxWidth="sm">
        <DialogTitle>{t('super.admins.deactivate_title')}</DialogTitle>
        <DialogContent><DialogContentText>{t('super.admins.deactivate_confirm', { name: admin.name })}</DialogContentText></DialogContent>
        <DialogActions>
          <Button onClick={() => setDialog(null)}>{t('super.actions.cancel')}</Button>
          <Button variant="contained" color="error" onClick={deactivate}>{t('super.admins.deactivate_button')}</Button>
        </DialogActions>
      </Dialog>

      <PasswordDialog
        open={dialog === 'reset'}
        title={t('super.admins.reset_title')}
        body={t('super.admins.reset_confirm', { name: admin.name })}
        submitLabel={t('super.admins.reset_button')}
        color="warning"
        onClose={() => { setDialog(null); resetForm.clearErrors(); }}
        onSubmit={resetTwoFactor}
        processing={resetForm.processing}
        error={resetForm.errors.password}
      />

      <PasswordDialog
        open={dialog === 'delete'}
        title={t('super.admins.delete_title')}
        body={t('super.admins.delete_confirm', { name: admin.name })}
        submitLabel={t('super.admins.delete_button')}
        color="error"
        onClose={() => { setDialog(null); deleteForm.clearErrors(); }}
        onSubmit={destroy}
        processing={deleteForm.processing}
        error={deleteForm.errors.password}
      />
    </Box>
  );
}

Edit.layout = (page: ReactNode) => <PanelLayout title="super.admins.edit_title">{page}</PanelLayout>;
