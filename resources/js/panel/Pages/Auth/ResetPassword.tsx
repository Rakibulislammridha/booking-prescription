// Staff password reset form (token from the e-mail link). Routes: panel.password.store (POST).
import type { FormEvent, ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import TextField from '@mui/material/TextField';
import Button from '@mui/material/Button';
import { GuestLayout } from '@panel/Layouts/GuestLayout';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{ token: string; email: string }>;

export default function ResetPassword({ token, email }: Props) {
  const { t } = useTranslation();
  const form = useForm({ token, email, password: '', password_confirmation: '' });

  const submit = (e: FormEvent<HTMLFormElement>): void => {
    e.preventDefault();
    form.post(route('panel.password.store'), { onFinish: () => form.reset('password', 'password_confirmation') });
  };

  return (
    <Box component="form" onSubmit={submit} noValidate sx={{ display: 'grid', gap: 2 }}>
      <TextField label={t('auth.email')} type="email" name="email" autoComplete="username" required value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} error={Boolean(form.errors.email)} helperText={form.errors.email} />
      <TextField label={t('auth.new_password')} type="password" name="password" autoComplete="new-password" autoFocus required value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} error={Boolean(form.errors.password)} helperText={form.errors.password} />
      <TextField label={t('auth.confirm_password')} type="password" name="password_confirmation" autoComplete="new-password" required value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} error={Boolean(form.errors.password_confirmation)} helperText={form.errors.password_confirmation} />
      <Button type="submit" variant="contained" size="large" disabled={form.processing}>{t('auth.reset_password')}</Button>
    </Box>
  );
}

ResetPassword.layout = (page: ReactNode) => <GuestLayout title="auth.reset_password_title">{page}</GuestLayout>;
