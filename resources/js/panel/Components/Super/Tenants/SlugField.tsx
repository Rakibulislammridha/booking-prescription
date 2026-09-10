// The clinic's address, checked as it is typed: `{slug}.{central}` must be a well-formed DNS label, not one of the
// platform's reserved names, and not claimed — including by a soft-deleted clinic. The server answers the same
// three questions on submit; this only saves the operator a round trip.
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import CircularProgress from '@mui/material/CircularProgress';
import InputAdornment from '@mui/material/InputAdornment';
import TextField from '@mui/material/TextField';
import CheckCircleOutlineIcon from '@mui/icons-material/CheckCircleOutlined';
import ErrorOutlineIcon from '@mui/icons-material/ErrorOutlined';
import { checkSlug } from '@panel/api/superTenants';
import type { SlugAvailability } from './types';

const CHECK_DEBOUNCE_MS = 300;
const SLUG_PATTERN = /^[a-z0-9][a-z0-9-]{1,62}$/;

export interface SlugFieldProps {
  value: string;
  onChange: (slug: string) => void;
  centralDomain: string;
  /** Server-side validation message, when the form came back with one. */
  error?: string;
  /** public_id of the tenant being renamed, so its current slug does not read as taken. */
  ignore?: string;
  onAvailability?: (state: SlugAvailability) => void;
  label?: string;
  autoFocus?: boolean;
  disabled?: boolean;
}

const MESSAGE: Record<Exclude<SlugAvailability, 'idle'>, string> = {
  checking: 'super.tenants.form.slug_checking',
  available: 'super.tenants.form.slug_available',
  taken: 'super.tenants.form.slug_taken',
  reserved: 'super.tenants.form.slug_reserved',
  invalid: 'super.tenants.form.slug_invalid',
};

/** Lower-case, spaces to hyphens, nothing outside [a-z0-9-]: what the server would do to it anyway. */
export function normaliseSlug(raw: string): string {
  return raw.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '');
}

export function SlugField({ value, onChange, centralDomain, error, ignore, onAvailability, label, autoFocus, disabled }: SlugFieldProps) {
  const { t } = useTranslation();
  const [state, setState] = useState<SlugAvailability>('idle');
  const report = useRef(onAvailability);
  report.current = onAvailability;

  useEffect(() => {
    const slug = value.trim();
    if (slug === '') {
      setState('idle');
      report.current?.('idle');
      return;
    }
    if (!SLUG_PATTERN.test(slug)) {
      setState('invalid');
      report.current?.('invalid');
      return;
    }

    setState('checking');
    report.current?.('checking');
    const controller = new AbortController();
    const timer = window.setTimeout(() => {
      checkSlug(slug, ignore, controller.signal)
        .then((result) => {
          const next: SlugAvailability = result.available ? 'available' : (result.reason ?? 'invalid');
          setState(next);
          report.current?.(next);
        })
        .catch(() => {
          if (controller.signal.aborted) return;
          setState('idle');
          report.current?.('idle');
        });
    }, CHECK_DEBOUNCE_MS);

    return () => {
      controller.abort();
      window.clearTimeout(timer);
    };
  }, [value, ignore]);

  const bad = state === 'taken' || state === 'reserved' || state === 'invalid';
  const helper = error ?? (state === 'idle' ? t('super.tenants.form.slug_help') : t(MESSAGE[state]));
  const host = value.trim() === '' ? `….${centralDomain}` : `${value.trim()}.${centralDomain}`;

  return (
    <Box>
      <TextField
        label={label ?? t('super.tenants.form.slug')}
        value={value}
        onChange={(e) => onChange(normaliseSlug(e.target.value))}
        error={Boolean(error) || bad}
        helperText={helper}
        required
        fullWidth
        autoFocus={autoFocus}
        disabled={disabled}
        slotProps={{
          htmlInput: { maxLength: 63, spellCheck: false, autoCapitalize: 'none', autoCorrect: 'off', 'data-testid': 'slug-input' },
          input: {
            endAdornment: (
              <InputAdornment position="end">
                {state === 'checking' ? <CircularProgress size={16} /> : null}
                {state === 'available' ? <CheckCircleOutlineIcon color="success" fontSize="small" /> : null}
                {bad ? <ErrorOutlineIcon color="error" fontSize="small" /> : null}
              </InputAdornment>
            ),
          },
        }}
      />
      <Box component="span" sx={{ display: 'block', mt: 0.5, fontFamily: 'monospace', fontSize: 13, color: 'text.secondary' }}>{host}</Box>
    </Box>
  );
}
