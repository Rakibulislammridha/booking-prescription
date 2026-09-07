// Staff login (guard `web`, ARCHITECTURE §6.2). Routes: panel.login (GET), panel.login.store (POST), panel.password.request.
import type { FormEvent, ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import TextField from '@mui/material/TextField';
import Button from '@mui/material/Button';
import Checkbox from '@mui/material/Checkbox';
import FormControlLabel from '@mui/material/FormControlLabel';
import Alert from '@mui/material/Alert';
import Link from '@mui/material/Link';
import { GuestLayout } from '@panel/Layouts/GuestLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { hasRoute, route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type LoginProps = PageProps<{ status?: string | null; canResetPassword?: boolean }>;

export default function Login({ status, canResetPassword = true }: LoginProps) {
  const { t } = useTranslation();
  const form = useForm({ email: '', password: '', remember: false });

  const submit = (e: FormEvent<HTMLFormElement>): void => {
    e.preventDefault();
    form.post(route('panel.login.store'), { onFinish: () => form.reset('password') });
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
      <FormControlLabel
        control={<Checkbox name="remember" checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} />}
        label={t('auth.remember')}
      />
      <Button type="submit" variant="contained" size="large" disabled={form.processing}>
        {t('auth.login')}
      </Button>
      {canResetPassword && hasRoute('panel.password.request') ? (
        <Link component={RouterLink} href={route('panel.password.request')} variant="body2" sx={{ textAlign: "center" }}>
          {t('auth.forgot_password')}
        </Link>
      ) : null}
    </Box>
  );
}

Login.layout = (page: ReactNode) => <GuestLayout title="auth.login_title">{page}</GuestLayout>;
