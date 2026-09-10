// How a clinic user gets their first way in: a password the operator types now, or a set-password link minted on
// the clinic's own host and shown once. Either way the account starts with "must change password" on.
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import FormControl from '@mui/material/FormControl';
import FormControlLabel from '@mui/material/FormControlLabel';
import FormHelperText from '@mui/material/FormHelperText';
import FormLabel from '@mui/material/FormLabel';
import IconButton from '@mui/material/IconButton';
import InputAdornment from '@mui/material/InputAdornment';
import Radio from '@mui/material/Radio';
import RadioGroup from '@mui/material/RadioGroup';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import VisibilityIcon from '@mui/icons-material/Visibility';
import VisibilityOffIcon from '@mui/icons-material/VisibilityOff';
import type { CredentialKind } from './types';

export interface CredentialChoiceProps {
  kind: CredentialKind;
  onKind: (kind: CredentialKind) => void;
  password: string;
  onPassword: (password: string) => void;
  error?: string;
  disabled?: boolean;
}

export function CredentialChoice({ kind, onKind, password, onPassword, error, disabled }: CredentialChoiceProps) {
  const { t } = useTranslation();
  const [visible, setVisible] = useState(false);

  return (
    <Stack spacing={1.5}>
      <FormControl disabled={disabled}>
        <FormLabel id="credential-kind">{t('super.tenants.form.credential')}</FormLabel>
        <RadioGroup aria-labelledby="credential-kind" value={kind} onChange={(e) => onKind(e.target.value as CredentialKind)}>
          <FormControlLabel value="link" control={<Radio />} label={t('super.tenants.form.credential_link')} />
          <FormHelperText sx={{ mt: -0.5, ml: 4 }}>{t('super.tenants.form.credential_link_help')}</FormHelperText>
          <FormControlLabel value="password" control={<Radio />} label={t('super.tenants.form.credential_password')} />
          <FormHelperText sx={{ mt: -0.5, ml: 4 }}>{t('super.tenants.form.credential_password_help')}</FormHelperText>
        </RadioGroup>
      </FormControl>
      {kind === 'password' ? (
        <TextField
          label={t('super.tenants.form.password')}
          type={visible ? 'text' : 'password'}
          value={password}
          onChange={(e) => onPassword(e.target.value)}
          error={Boolean(error)}
          helperText={error ?? t('super.tenants.form.password_help')}
          required
          disabled={disabled}
          autoComplete="new-password"
          slotProps={{
            htmlInput: { minLength: 8, maxLength: 72, 'data-testid': 'password-input' },
            input: {
              endAdornment: (
                <InputAdornment position="end">
                  <IconButton size="small" onClick={() => setVisible((v) => !v)} aria-label={t(visible ? 'super.tenants.form.hide_password' : 'super.tenants.form.show_password')}>
                    {visible ? <VisibilityOffIcon fontSize="small" /> : <VisibilityIcon fontSize="small" />}
                  </IconButton>
                </InputAdornment>
              ),
            },
          }}
        />
      ) : null}
    </Stack>
  );
}
