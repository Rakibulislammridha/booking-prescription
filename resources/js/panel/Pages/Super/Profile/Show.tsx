// Super/Profile/Show — the operator's own account, from the top-right menu: name and email (moving the email
// re-asks the password), a password change (ends every OTHER session), the devices this account is signed in
// on, and where the second factor is managed. Acts only on the signed-in operator; there is no id in the URL.
import { type FormEvent, type ReactNode } from 'react';
import { router, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import LogoutIcon from '@mui/icons-material/Logout';
import SecurityIcon from '@mui/icons-material/Security';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { SuperNav } from '@panel/Components/Super/SuperNav';
import type { SuperProfile } from '@panel/Components/Super/types';
import { useSharedProps } from '@shared/inertia';
import { formatNumber } from '@shared/format/number';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { hasRoute, route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type { StaffSession } from '@shared/types/models';

type Props = PageProps<{
  profile: SuperProfile;
  sessions: StaffSession[];
  idle_timeout_minutes: number;
}>;

export default function Show({ profile, sessions, idle_timeout_minutes }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const shared = useSharedProps();
  const identity = useForm({ name: profile.name, email: profile.email, current_password: '' });
  const password = useForm({ current_password: '', password: '', password_confirmation: '' });
  const emailMoves = identity.data.email.trim().toLowerCase() !== profile.email;
  const others = sessions.filter((s) => !s.is_current).length;

  const saveIdentity = (e: FormEvent<HTMLFormElement>): void => {
    e.preventDefault();
    identity.put(route('super.profile.update'), { preserveScroll: true, onFinish: () => identity.reset('current_password') });
  };
  const changePassword = (e: FormEvent<HTMLFormElement>): void => {
    e.preventDefault();
    password.put(route('super.profile.password'), { preserveScroll: true, onFinish: () => password.reset() });
  };
  const revoke = (ref: string): void => {
    router.delete(route('super.profile.sessions.destroy', { ref }), { preserveScroll: true });
  };
  const revokeOthers = (): void => {
    if (!window.confirm(t('super.profile.sessions.confirm_others'))) return;
    router.delete(route('super.profile.sessions.destroy_others'), { preserveScroll: true });
  };

  const twoFactorLabel = t(`super.admins.two_factor.${profile.two_factor}`, { defaultValue: profile.two_factor });

  return (
    <Box>
      <SuperNav />

      <Stack spacing={2} sx={{ maxWidth: 880 }}>
        {shared.errors.domain ? <Alert severity="error">{shared.errors.domain}</Alert> : null}

        <Card variant="outlined">
          <CardContent>
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} sx={{ alignItems: { sm: 'center' } }}>
              <Box sx={{ flexGrow: 1, minWidth: 0 }}>
                <Typography variant="h6" component="h2" lang="bn">{profile.name}</Typography>
                <Typography variant="body2" color="text.secondary">{profile.email}</Typography>
                <Typography variant="caption" color="text.secondary" component="div" sx={{ mt: 0.5 }}>
                  {profile.last_login_at
                    ? t('super.profile.last_login', { at: formatDhaka(profile.last_login_at, 'D MMM YYYY, h:mm a', locale), ip: profile.last_login_ip ?? '—' })
                    : t('super.admins.never')}
                </Typography>
              </Box>
              <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
                <Chip
                  size="small"
                  color={profile.two_factor === 'enabled' ? 'success' : profile.two_factor === 'enrolling' ? 'warning' : 'default'}
                  label={`${t('super.admins.column.two_factor')}: ${twoFactorLabel}`}
                />
                {hasRoute('super.two-factor.show') ? (
                  <Button size="small" variant="outlined" startIcon={<SecurityIcon />} component={RouterLink} href={route('super.two-factor.show')}>
                    {t('super.profile.security_link')}
                  </Button>
                ) : null}
              </Stack>
            </Stack>
            {profile.two_factor_policy === 'disabled' ? (
              <Typography variant="caption" color="text.secondary" component="div" sx={{ mt: 1 }}>{t('super.profile.two_factor_policy_disabled')}</Typography>
            ) : profile.two_factor !== 'enabled' ? (
              <Alert severity="warning" sx={{ mt: 1.5 }}>{t('super.profile.two_factor_missing')}</Alert>
            ) : (
              <Typography variant="caption" color="text.secondary" component="div" sx={{ mt: 1 }}>
                {t('super.admins.recovery_codes', { count: formatNumber(profile.recovery_codes, locale) })}
              </Typography>
            )}
          </CardContent>
        </Card>

        <Card variant="outlined">
          <CardContent>
            <Typography variant="subtitle1" component="h3" sx={{ mb: 1.5 }}>{t('super.profile.identity_title')}</Typography>
            <Box component="form" onSubmit={saveIdentity} noValidate sx={{ display: 'grid', gap: 2, maxWidth: 480 }}>
              <TextField
                label={t('super.admins.field.name')} name="name" size="small" required
                value={identity.data.name} onChange={(e) => identity.setData('name', e.target.value)}
                error={Boolean(identity.errors.name)} helperText={identity.errors.name}
              />
              <TextField
                label={t('super.admins.field.email')} name="email" type="email" size="small" required
                value={identity.data.email} onChange={(e) => identity.setData('email', e.target.value)}
                error={Boolean(identity.errors.email)} helperText={identity.errors.email ?? t('super.profile.email_help')}
              />
              {emailMoves ? (
                <TextField
                  label={t('super.admins.field.current_password')} name="current_password" type="password" size="small" autoComplete="current-password"
                  value={identity.data.current_password} onChange={(e) => identity.setData('current_password', e.target.value)}
                  error={Boolean(identity.errors.current_password)} helperText={identity.errors.current_password ?? t('super.profile.email_password_help')}
                />
              ) : null}
              <Box><Button type="submit" variant="contained" disabled={identity.processing || !identity.isDirty}>{t('super.actions.save')}</Button></Box>
            </Box>
          </CardContent>
        </Card>

        <Card variant="outlined">
          <CardContent>
            <Typography variant="subtitle1" component="h3" sx={{ mb: 0.5 }}>{t('super.profile.password_title')}</Typography>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>{t('super.profile.password_help')}</Typography>
            <Box component="form" onSubmit={changePassword} noValidate sx={{ display: 'grid', gap: 2, maxWidth: 480 }}>
              <TextField
                label={t('super.profile.field.current_password')} name="current_password" type="password" size="small" autoComplete="current-password"
                value={password.data.current_password} onChange={(e) => password.setData('current_password', e.target.value)}
                error={Boolean(password.errors.current_password)} helperText={password.errors.current_password}
              />
              <TextField
                label={t('auth.new_password')} name="password" type="password" size="small" autoComplete="new-password"
                value={password.data.password} onChange={(e) => password.setData('password', e.target.value)}
                error={Boolean(password.errors.password)} helperText={password.errors.password ?? t('super.profile.password_rule')}
              />
              <TextField
                label={t('auth.confirm_password')} name="password_confirmation" type="password" size="small" autoComplete="new-password"
                value={password.data.password_confirmation} onChange={(e) => password.setData('password_confirmation', e.target.value)}
                error={Boolean(password.errors.password_confirmation)} helperText={password.errors.password_confirmation}
              />
              <Box><Button type="submit" variant="contained" disabled={password.processing || password.data.password === ''}>{t('super.profile.password_submit')}</Button></Box>
            </Box>
          </CardContent>
        </Card>

        <Card variant="outlined">
          <CardContent sx={{ pb: 1 }}>
            <Typography variant="subtitle1" component="h3">{t('super.profile.sessions.title')}</Typography>
            <Typography variant="body2" color="text.secondary">{t('super.profile.sessions.subtitle')}</Typography>
            {idle_timeout_minutes > 0 ? (
              <Typography variant="caption" color="text.secondary" component="div" sx={{ mt: 0.5 }}>
                {t('super.profile.sessions.idle_note', { minutes: formatNumber(idle_timeout_minutes, locale) })}
              </Typography>
            ) : null}
          </CardContent>
          <Box sx={{ overflowX: 'auto' }}>
            <Table size="small" aria-label={t('super.profile.sessions.title')}>
              <TableHead>
                <TableRow>
                  <TableCell>{t('super.profile.sessions.column.device')}</TableCell>
                  <TableCell>{t('super.profile.sessions.column.ip')}</TableCell>
                  <TableCell>{t('super.profile.sessions.column.signed_in')}</TableCell>
                  <TableCell>{t('super.profile.sessions.column.last_seen')}</TableCell>
                  <TableCell />
                </TableRow>
              </TableHead>
              <TableBody>
                {sessions.length === 0 ? (
                  <TableRow><TableCell colSpan={5}><Typography variant="body2" color="text.secondary" sx={{ py: 2, textAlign: 'center' }}>{t('super.profile.sessions.empty')}</Typography></TableCell></TableRow>
                ) : sessions.map((session) => (
                  <TableRow key={session.ref} hover>
                    <TableCell>
                      <Typography variant="body2" sx={{ fontWeight: 600 }}>
                        {session.device}
                        {session.is_current ? <Chip size="small" color="primary" label={t('super.profile.sessions.current')} sx={{ ml: 1 }} /> : null}
                      </Typography>
                    </TableCell>
                    <TableCell sx={{ fontFamily: 'monospace' }}>{session.ip ?? '—'}</TableCell>
                    <TableCell>{formatDhaka(session.login_at, 'D MMM, h:mm a', locale)}</TableCell>
                    <TableCell>{formatDhaka(session.last_seen_at, 'D MMM, h:mm a', locale)}</TableCell>
                    <TableCell align="right">
                      <Button size="small" color="error" startIcon={<LogoutIcon />} onClick={() => revoke(session.ref)}>
                        {t('super.profile.sessions.revoke')}
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Box>
          {others > 0 ? (
            <Box sx={{ p: 1.5 }}>
              <Button variant="outlined" color="error" size="small" onClick={revokeOthers}>{t('super.profile.sessions.revoke_others')}</Button>
            </Box>
          ) : null}
        </Card>
      </Stack>
    </Box>
  );
}

Show.layout = (page: ReactNode) => <PanelLayout title="super.profile.title">{page}</PanelLayout>;
