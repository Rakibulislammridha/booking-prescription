// Super/Settings/Index — platform-wide settings (public.platform_settings, SCHEMA §2.19), rendered from the
// registry: every key `PlatformSettingsRegistry` declares arrives as a row with its control shape and its copy,
// so a new key appears here with no UI work. One card per key, saved one at a time; a key marked
// `requires_password` (every `security.*` key) re-asks the operator's current password — the password, not a
// TOTP code, because the first such key is the switch that turns the second factor off.
import { useState, type FormEvent, type ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import FormControl from '@mui/material/FormControl';
import FormControlLabel from '@mui/material/FormControlLabel';
import FormHelperText from '@mui/material/FormHelperText';
import Radio from '@mui/material/Radio';
import RadioGroup from '@mui/material/RadioGroup';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { SuperNav } from '@panel/Components/Super/SuperNav';
import type { PlatformSettingGroup, PlatformSettingRow } from '@panel/Components/Super/types';
import { formatDhaka } from '@shared/format/date';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{ groups: PlatformSettingGroup[] }>;

type SettingValue = PlatformSettingRow['value'];

interface SettingForm {
  value: SettingValue;
  password: string;
}

function describeValue(row: PlatformSettingRow, value: SettingValue, t: (key: string) => string): string {
  if (row.options) return row.options.find((o) => o.value === value)?.label ?? String(value ?? '');
  if (row.type === 'bool') return value ? t('super.settings.bool_on') : t('super.settings.bool_off');
  return String(value ?? '');
}

function SettingCard({ row }: { row: PlatformSettingRow }) {
  const { t } = useTranslation();
  // A secret arrives masked and is edited by retyping: the form starts blank, and blank on save means "keep".
  const form = useForm<SettingForm>({ value: row.secret ? '' : row.value, password: '' });
  const [visible, setVisible] = useState(false);

  const dirty = row.secret ? form.data.value !== '' : form.data.value !== row.value;
  // A refused write (unknown key, a value the registry rejects) comes back as the `domain` error bag entry.
  const domainError = (form.errors as Record<string, string | undefined>).domain;
  const inputId = `setting-${row.key.replace(/\W+/g, '-')}`;

  const submit = (e: FormEvent<HTMLFormElement>): void => {
    e.preventDefault();
    form.put(route('super.settings.update', { key: row.key }), {
      preserveScroll: true,
      onFinish: () => form.reset('password'),
    });
  };

  const control = (): ReactNode => {
    if (row.options) {
      return (
        <FormControl component="fieldset" error={Boolean(form.errors.value)}>
          <RadioGroup
            aria-labelledby={`${inputId}-label`}
            name={row.key}
            value={String(form.data.value ?? '')}
            onChange={(e) => form.setData('value', e.target.value)}
          >
            {row.options.map((option) => (
              <FormControlLabel
                key={option.value}
                value={option.value}
                control={<Radio />}
                sx={{ alignItems: 'flex-start', mb: 1 }}
                label={(
                  <Box sx={{ pt: 1 }}>
                    <Typography variant="body1" sx={{ fontWeight: 600 }}>{option.label}</Typography>
                    <Typography variant="body2" color="text.secondary">{option.help}</Typography>
                  </Box>
                )}
              />
            ))}
          </RadioGroup>
          {form.errors.value ? <FormHelperText>{form.errors.value}</FormHelperText> : null}
        </FormControl>
      );
    }

    if (row.type === 'bool') {
      return (
        <FormControlLabel
          control={<Switch checked={Boolean(form.data.value)} onChange={(e) => form.setData('value', e.target.checked)} />}
          label={row.label}
        />
      );
    }

    // The registry's control hints: a textarea for multi-line copy, an email/tel/url box, numeric bounds.
    const inputType = row.secret && !visible ? 'password' : row.type === 'string' ? (row.input ?? 'text') : 'number';
    const bounds = row.type === 'number' ? { step: 'any' } : row.type === 'int' ? { step: 1, ...(row.min === null ? {} : { min: row.min }), ...(row.max === null ? {} : { max: row.max }) } : {};

    return (
      <TextField
        id={inputId}
        label={row.label}
        type={row.multiline ? 'text' : inputType}
        multiline={row.multiline}
        minRows={row.multiline ? 3 : undefined}
        size="small"
        value={form.data.value ?? ''}
        placeholder={row.secret && row.is_set ? String(row.value ?? '') : undefined}
        onChange={(e) => form.setData('value', row.type === 'string' ? e.target.value : e.target.value === '' ? null : Number(e.target.value))}
        onFocus={() => setVisible(row.secret)}
        onBlur={() => setVisible(false)}
        error={Boolean(form.errors.value)}
        helperText={form.errors.value ?? (row.secret ? t('super.settings.secret_keep') : row.type === 'int' && (row.min !== null || row.max !== null) ? t('super.settings.range', { min: row.min ?? '', max: row.max ?? '' }) : undefined)}
        slotProps={{ htmlInput: bounds }}
        sx={{ maxWidth: row.multiline ? 640 : 360 }}
      />
    );
  };

  return (
    <Card component="section" aria-labelledby={`${inputId}-label`}>
      <CardContent>
        <Box component="form" onSubmit={submit} noValidate sx={{ display: 'grid', gap: 2 }}>
          <Box>
            <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
              <Typography id={`${inputId}-label`} variant="h6" component="h2">{row.label}</Typography>
              {row.is_set ? null : <Chip size="small" label={t('super.settings.using_default')} />}
            </Stack>
            <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5 }}>{row.description}</Typography>
          </Box>

          {control()}

          {domainError ? <Alert severity="error">{domainError}</Alert> : null}

          {row.requires_password ? (
            <TextField
              label={t('super.settings.password')}
              type="password"
              name="password"
              autoComplete="current-password"
              size="small"
              value={form.data.password}
              onChange={(e) => form.setData('password', e.target.value)}
              error={Boolean(form.errors.password)}
              helperText={form.errors.password ?? t('super.settings.password_help')}
              sx={{ maxWidth: 360 }}
            />
          ) : null}

          <Stack direction="row" spacing={2} sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
            <Button type="submit" variant="contained" disabled={!dirty || form.processing}>{t('super.settings.save')}</Button>
            <Typography variant="caption" color="text.secondary">
              {row.secret ? null : `${t('super.settings.default_value', { value: describeValue(row, row.default, t) })} · `}
              {row.updated_at
                ? t(row.updated_by ? 'super.settings.last_changed' : 'super.settings.last_changed_system', { at: formatDhaka(row.updated_at, 'D MMM YYYY, h:mm a'), by: row.updated_by ?? '' })
                : t('super.settings.never_changed')}
            </Typography>
          </Stack>
        </Box>
      </CardContent>
    </Card>
  );
}

export default function Index({ groups }: Props) {
  const { t } = useTranslation();

  return (
    <Box>
      <SuperNav />

      <Stack spacing={3} sx={{ maxWidth: 800 }}>
        <Typography variant="body2" color="text.secondary">{t('super.settings.intro')}</Typography>

        {groups.map((group) => (
          <Stack key={group.key} spacing={2}>
            <Typography variant="overline" component="h2" color="text.secondary">{group.label}</Typography>
            {group.settings.map((row) => (
              // Keyed on the saved value too: a successful save reloads the props and remounts the card with a
              // fresh form; a refused one leaves the value alone and the errors on screen.
              <SettingCard key={`${row.key}:${String(row.value)}`} row={row} />
            ))}
          </Stack>
        ))}
      </Stack>
    </Box>
  );
}

Index.layout = (page: ReactNode) => <PanelLayout title="super.settings.title">{page}</PanelLayout>;
