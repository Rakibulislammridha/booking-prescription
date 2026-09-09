// Super-admin login on super.{central} (guard `super`, ARCHITECTURE §6.5). Routes: super.login (GET) / super.login.store (POST).
// Reuses GuestLayout: with no tenant on the central host it shows the platform name.
import type { FormEvent, ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import TextField from '@mui/material/TextField';
import Button from '@mui/material/Button';
import Alert from '@mui/material/Alert';
import { GuestLayout } from '@panel/Layouts/GuestLayout';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{ status?: string | null }>;

export default function Login({ status }: Props) {
  const { t } = useTranslation();
  // No "remember me" on the super guard (B4): the platform console never gets a durable recaller cookie.
  const form = useForm({ email: '', password: '' });

  const submit = (e: FormEvent<HTMLFormElement>): void => {
    e.preventDefault();
    form.post(route('super.login.store'), { onFinish: () => form.reset('password') });
  };

  return (
    <Box component="form" onSubmit={submit} noValidate sx={{ display: 'grid', gap: 2 }}>
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
      <TextField
        label={t('auth.password')}
        type="password"
        name="password"
        autoComplete="current-password"
        required
        value={form.data.password}
        onChange={(e) => form.setData('password', e.target.value)}
        error={Boolean(form.errors.password)}
        helperText={form.errors.password}
      />
      <Button type="submit" variant="contained" size="large" disabled={form.processing}>
        {t('auth.login')}
      </Button>
    </Box>
  );
}

Login.layout = (page: ReactNode) => <GuestLayout title="auth.super_login_title">{page}</GuestLayout>;
