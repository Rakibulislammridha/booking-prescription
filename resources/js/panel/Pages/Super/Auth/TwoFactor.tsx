// Super/Auth/TwoFactor — the operator's own second factor (ARCHITECTURE §6.5).
//
// Also the screen `EnsureSuperTwoFactor` sends an un-enrolled operator to when 2FA is required, which is why the
// enrolment card leads and says plainly that nothing else in the console will open until it is done.
//
// Three states, one page: not enrolled → enrolling (QR + confirm) → enabled (recovery codes, rotate, disable).
import { useState, type FormEvent, type ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Divider from '@mui/material/Divider';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { SuperNav } from '@panel/Components/Super/SuperNav';
import { formatDhaka } from '@shared/format/date';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

interface Enrolment {
  secret: string;
  otpauth_uri: string;
  qr_svg: string;
}

type Props = PageProps<{
  enabled: boolean;
  required: boolean;
  confirmed_at: string | null;
  recovery_remaining: number;
  enrolment: Enrolment | null;
  recovery_codes?: string[] | null;
}>;

export default function TwoFactor({ enabled, required, confirmed_at, recovery_remaining, enrolment, recovery_codes }: Props) {
  const { t } = useTranslation();
  const [confirming, setConfirming] = useState(false);

  const startForm = useForm({});
  const confirmForm = useForm({ code: '' });
  // Both credential-changing actions re-ask for the password AND a current authenticator code (B4).
  const disableForm = useForm({ password: '', code: '' });
  const rotateForm = useForm({ password: '', code: '' });

  const start = (): void => { startForm.post(route('super.two-factor.store'), { preserveScroll: true }); };
  const confirm = (e: FormEvent<HTMLFormElement>): void => {
    e.preventDefault();
    setConfirming(true);
    confirmForm.post(route('super.two-factor.confirm'), { preserveScroll: true, onFinish: () => { setConfirming(false); confirmForm.reset('code'); } });
  };
  const regenerate = (e: FormEvent<HTMLFormElement>): void => {
    e.preventDefault();
    rotateForm.post(route('super.two-factor.recovery-codes'), { preserveScroll: true, onFinish: () => rotateForm.reset('password', 'code') });
  };
  const disable = (e: FormEvent<HTMLFormElement>): void => {
    e.preventDefault();
    disableForm.delete(route('super.two-factor.destroy'), { preserveScroll: true, onFinish: () => disableForm.reset('password', 'code') });
  };

  return (
    <Box>
      <SuperNav />

      <Stack spacing={2} sx={{ maxWidth: 720 }}>
        {!enabled && required ? <Alert severity="warning">{t('auth.two_factor.enrolment_required')}</Alert> : null}

        {recovery_codes && recovery_codes.length > 0 ? (
          <Alert severity="success">
            <Typography variant="subtitle2" sx={{ mb: 1 }}>{t('auth.two_factor.recovery_codes_title')}</Typography>
            <Typography variant="body2" sx={{ mb: 1 }}>{t('auth.two_factor.recovery_codes_help')}</Typography>
            <Box component="ul" sx={{ m: 0, pl: 3, fontFamily: 'monospace', columns: { xs: 1, sm: 2 } }}>
              {recovery_codes.map((code) => <li key={code}>{code}</li>)}
            </Box>
          </Alert>
        ) : null}

        <Card>
          <CardContent>
            <Stack direction="row" spacing={1} sx={{ alignItems: 'center', mb: 1 }}>
              <Typography variant="h6" component="h2">{t('auth.two_factor.title')}</Typography>
              <Chip
                size="small"
                color={enabled ? 'success' : 'default'}
                label={enabled ? t('auth.two_factor.status_enabled') : t('auth.two_factor.status_disabled')}
              />
            </Stack>
            <Typography variant="body2" color="text.secondary">{t('auth.two_factor.intro')}</Typography>

            {enabled ? (
              <Stack spacing={2} sx={{ mt: 2 }}>
                <Typography variant="body2">
                  {t('auth.two_factor.enabled_since', { at: confirmed_at ? formatDhaka(confirmed_at, 'D MMM YYYY, h:mm a') : '—' })}
                </Typography>
                <Typography variant="body2">{t('auth.two_factor.recovery_remaining', { remaining: recovery_remaining })}</Typography>
                <Box component="form" onSubmit={regenerate} noValidate sx={{ display: 'grid', gap: 1.5, maxWidth: 320 }}>
                  <Typography variant="body2" color="text.secondary">{t('auth.two_factor.regenerate_recovery_help')}</Typography>
                  <TextField
                    label={t('auth.password')}
                    type="password"
                    name="password"
                    autoComplete="current-password"
                    size="small"
                    value={rotateForm.data.password}
                    onChange={(e) => rotateForm.setData('password', e.target.value)}
                    error={Boolean(rotateForm.errors.password)}
                    helperText={rotateForm.errors.password}
                  />
                  <TextField
                    label={t('auth.two_factor.code')}
                    name="code"
                    autoComplete="one-time-code"
                    size="small"
                    value={rotateForm.data.code}
                    onChange={(e) => rotateForm.setData('code', e.target.value)}
                    error={Boolean(rotateForm.errors.code)}
                    helperText={rotateForm.errors.code}
                    slotProps={{ htmlInput: { inputMode: 'numeric', maxLength: 6, pattern: '[0-9]*' } }}
                  />
                  <Box><Button type="submit" variant="outlined" size="small" disabled={rotateForm.processing}>{t('auth.two_factor.regenerate_recovery')}</Button></Box>
                </Box>

                <Divider />

                <Box component="form" onSubmit={disable} noValidate sx={{ display: 'grid', gap: 1.5, maxWidth: 320 }}>
                  <Typography variant="subtitle2">{t('auth.two_factor.disable_title')}</Typography>
                  <Typography variant="body2" color="text.secondary">{t('auth.two_factor.disable_help')}</Typography>
                  <TextField
                    label={t('auth.password')}
                    type="password"
                    name="password"
                    autoComplete="current-password"
                    size="small"
                    value={disableForm.data.password}
                    onChange={(e) => disableForm.setData('password', e.target.value)}
                    error={Boolean(disableForm.errors.password)}
                    helperText={disableForm.errors.password}
                  />
                  <TextField
                    label={t('auth.two_factor.code')}
                    name="code"
                    autoComplete="one-time-code"
                    size="small"
                    value={disableForm.data.code}
                    onChange={(e) => disableForm.setData('code', e.target.value)}
                    error={Boolean(disableForm.errors.code)}
                    helperText={disableForm.errors.code}
                    slotProps={{ htmlInput: { inputMode: 'numeric', maxLength: 6, pattern: '[0-9]*' } }}
                  />
                  <Box><Button type="submit" color="error" variant="outlined" size="small" disabled={disableForm.processing}>{t('auth.two_factor.disable')}</Button></Box>
                </Box>
              </Stack>
            ) : enrolment ? (
              <Stack spacing={2} sx={{ mt: 2 }}>
                <Typography variant="body2">{t('auth.two_factor.scan_help')}</Typography>
                <Box
                  component="img"
                  src={enrolment.qr_svg}
                  alt={t('auth.two_factor.qr_alt')}
                  sx={{ width: 200, height: 200, border: 1, borderColor: 'divider', p: 1, bgcolor: 'common.white' }}
                />
                <Box>
                  <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('auth.two_factor.manual_entry')}</Typography>
                  <Typography sx={{ fontFamily: 'monospace', letterSpacing: 1 }}>{enrolment.secret}</Typography>
                </Box>

                <Box component="form" onSubmit={confirm} noValidate sx={{ display: 'grid', gap: 1.5, maxWidth: 320 }}>
                  <Typography variant="body2">{t('auth.two_factor.confirm_help')}</Typography>
                  <TextField
                    label={t('auth.two_factor.code')}
                    name="code"
                    autoComplete="one-time-code"
                    size="small"
                    value={confirmForm.data.code}
                    onChange={(e) => confirmForm.setData('code', e.target.value)}
                    error={Boolean(confirmForm.errors.code)}
                    helperText={confirmForm.errors.code}
                    slotProps={{ htmlInput: { inputMode: 'numeric', maxLength: 6, pattern: '[0-9]*' } }}
                  />
                  <Box><Button type="submit" variant="contained" disabled={confirming || confirmForm.processing}>{t('auth.two_factor.confirm')}</Button></Box>
                </Box>
              </Stack>
            ) : (
              <Box sx={{ mt: 2 }}>
                <Button variant="contained" onClick={start} disabled={startForm.processing}>{t('auth.two_factor.enrol')}</Button>
              </Box>
            )}
          </CardContent>
        </Card>
      </Stack>
    </Box>
  );
}

TwoFactor.layout = (page: ReactNode) => <PanelLayout title="auth.two_factor.title">{page}</PanelLayout>;
