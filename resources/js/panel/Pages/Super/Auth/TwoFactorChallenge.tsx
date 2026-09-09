// The second half of the super login (ARCHITECTURE §6.5): the password matched, nobody is signed in yet.
// Routes: super.two-factor.challenge (GET) / super.two-factor.challenge.store (POST).
//
// One field at a time. A person here is holding a phone and reading six digits, so the code box is the whole
// screen and the recovery path is a link, not a second input competing for attention.
import { useState, type FormEvent, type ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { GuestLayout } from '@panel/Layouts/GuestLayout';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{ recovery_available: boolean }>;

interface ChallengeForm {
  code: string;
  recovery_code: string;
}

export default function TwoFactorChallenge({ recovery_available }: Props) {
  const { t } = useTranslation();
  const [recovery, setRecovery] = useState(false);
  const form = useForm<ChallengeForm>({ code: '', recovery_code: '' });

  const submit = (e: FormEvent<HTMLFormElement>): void => {
    e.preventDefault();
    // Only ever send the half being used: a stale value in the other field would decide which branch the
    // server takes and burn an attempt on a code the person did not type.
    form.transform((data) => (recovery ? { recovery_code: data.recovery_code } : { code: data.code }));
    form.post(route('super.two-factor.challenge.store'), { onFinish: () => form.reset('code', 'recovery_code') });
  };

  return (
    <Box component="form" onSubmit={submit} noValidate sx={{ display: 'grid', gap: 2 }}>
      <Typography variant="body2" color="text.secondary">
        {recovery ? t('auth.two_factor.recovery_help') : t('auth.two_factor.challenge_help')}
      </Typography>

      {recovery ? (
        <TextField
          label={t('auth.two_factor.recovery_code')}
          name="recovery_code"
          autoComplete="one-time-code"
          autoFocus
          required
          value={form.data.recovery_code}
          onChange={(e) => form.setData('recovery_code', e.target.value)}
          error={Boolean(form.errors.recovery_code)}
          helperText={form.errors.recovery_code}
        />
      ) : (
        <TextField
          label={t('auth.two_factor.code')}
          name="code"
          autoComplete="one-time-code"
          autoFocus
          required
          value={form.data.code}
          onChange={(e) => form.setData('code', e.target.value)}
          error={Boolean(form.errors.code)}
          helperText={form.errors.code}
          slotProps={{ htmlInput: { inputMode: 'numeric', maxLength: 6, pattern: '[0-9]*' } }}
        />
      )}

      <Button type="submit" variant="contained" size="large" disabled={form.processing}>
        {t('auth.two_factor.verify')}
      </Button>

      {recovery_available ? (
        <Button size="small" onClick={() => { setRecovery(!recovery); form.clearErrors(); }}>
          {recovery ? t('auth.two_factor.use_code') : t('auth.two_factor.use_recovery')}
        </Button>
      ) : null}
    </Box>
  );
}

TwoFactorChallenge.layout = (page: ReactNode) => <GuestLayout title="auth.two_factor.challenge_title">{page}</GuestLayout>;
