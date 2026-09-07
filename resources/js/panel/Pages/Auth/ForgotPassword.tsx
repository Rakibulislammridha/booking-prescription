// Staff password reset request (password broker `users`, ARCHITECTURE §6.1). Routes: panel.password.email (POST).
import type { FormEvent, ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import TextField from '@mui/material/TextField';
import Button from '@mui/material/Button';
import Alert from '@mui/material/Alert';
import Link from '@mui/material/Link';
import Typography from '@mui/material/Typography';
import { GuestLayout } from '@panel/Layouts/GuestLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{ status?: string }>;

export default function ForgotPassword({ status }: Props) {
  const { t } = useTranslation();
  const form = useForm({ email: '' });

  const submit = (e: FormEvent<HTMLFormElement>): void => {
    e.preventDefault();
    form.post(route('panel.password.email'));
  };

  return (
    <Box component="form" onSubmit={submit} noValidate sx={{ display: 'grid', gap: 2 }}>
      <Typography variant="body2" color="text.secondary">{t('auth.forgot_password_help')}</Typography>
      {status ? <Alert severity="success">{status}</Alert> : null}
      <TextField
        label={t('auth.email')}
        type="email"
        name="email"
        autoComplete="username"
        autoFocus
        required
        value={form.data.email}
        onChange={(e) => form.setData('email', e.target.value)}
        error={Boolean(form.errors.email)}
        helperText={form.errors.email}
      />
      <Button type="submit" variant="contained" size="large" disabled={form.processing}>
        {t('auth.send_reset_link')}
      </Button>
      <Link component={RouterLink} href={route('panel.login')} variant="body2" sx={{ textAlign: "center" }}>
        {t('auth.back_to_login')}
      </Link>
    </Box>
  );
}

ForgotPassword.layout = (page: ReactNode) => <GuestLayout title="auth.forgot_password_title">{page}</GuestLayout>;
