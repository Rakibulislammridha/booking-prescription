// One input, chosen by the registry entry rather than by hand: `bool` is a switch, a `string` with `options` is a
// select, `int`/`number` are numeric with the registry's own min/max on the element, and `secret` is a credential.
// Adding a key to SettingsRegistry therefore adds a working, validated field to the page with no frontend change.
import { useTranslation } from 'react-i18next';
import Button from '@mui/material/Button';
import FormControlLabel from '@mui/material/FormControlLabel';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import type { SettingDefinition, SettingValue } from '@shared/types/models';

interface Props {
  settingKey: string;
  definition: SettingDefinition;
  value: SettingValue;
  /** For a `secret` key: the server's masked presence indicator (`••••1234`), or '' when nothing is stored. */
  mask?: string;
  error?: string;
  disabled?: boolean;
  onChange: (value: SettingValue) => void;
}

export function SettingField({ settingKey, definition, value, mask = '', error, disabled = false, onChange }: Props) {
  const { t, i18n } = useTranslation();
  // A key documented in SCHEMA Appendix B has a written label and (sometimes) help text. A key added to the
  // registry since then still renders: it falls back to the dotted key as its label and simply has no help line.
  // `exists()` rather than `defaultValue: ''` — i18next treats an empty default as absent and echoes the key.
  const labelKey = `clinic.settings.keys.${settingKey}.label`;
  const helpKey = `clinic.settings.keys.${settingKey}.help`;
  const label = i18n.exists(labelKey) ? t(labelKey) : settingKey;
  const help = i18n.exists(helpKey) ? t(helpKey) : '';
  const defaultHint = t('clinic.settings.default_hint', { value: String(definition.default) });

  // A credential. The plaintext never comes down from the server, so the box always starts empty and an empty
  // box means "leave the stored value alone" — the server enforces the same rule, this only says so out loud.
  // `null` is the explicit removal, which is why Remove is a separate affordance and not just clearing the field.
  if (definition.secret) {
    const stored = mask !== '';
    const removing = value === null;

    return (
      <Stack spacing={0.5}>
        <TextField
          fullWidth size="small" type="password" label={label} disabled={disabled || removing}
          value={typeof value === 'string' ? value : ''}
          autoComplete="new-password"
          onChange={(e) => onChange(e.target.value)}
          error={Boolean(error)}
          helperText={error ?? (removing
            ? t('clinic.settings.secret.removing')
            : stored ? t('clinic.settings.secret.stored', { mask }) : (help || t('clinic.settings.secret.unset')))}
          slotProps={{ htmlInput: { spellCheck: false, 'aria-label': label } }}
        />
        {stored && !disabled ? (
          <Stack direction="row" spacing={1}>
            <Button size="small" onClick={() => onChange(removing ? '' : null)}>
              {removing ? t('common.actions.cancel') : t('clinic.settings.secret.remove')}
            </Button>
          </Stack>
        ) : null}
      </Stack>
    );
  }

  if (definition.type === 'bool') {
    return (
      <Stack>
        <FormControlLabel
          control={<Switch checked={value === true} disabled={disabled} onChange={(e) => onChange(e.target.checked)} />}
          label={label}
        />
        <Typography variant="caption" color={error ? 'error' : 'text.secondary'}>{error ?? (help || defaultHint)}</Typography>
      </Stack>
    );
  }

  if (definition.options) {
    return (
      <TextField
        select fullWidth size="small" label={label} value={String(value ?? '')} disabled={disabled}
        onChange={(e) => onChange(e.target.value)}
        error={Boolean(error)}
        helperText={error ?? (help || defaultHint)}
      >
        {definition.options.map((option) => (
          <MenuItem key={option} value={option}>{t(`clinic.settings.options.${settingKey}.${option}`, { defaultValue: option })}</MenuItem>
        ))}
      </TextField>
    );
  }

  const numeric = definition.type === 'int' || definition.type === 'number';

  return (
    <TextField
      fullWidth size="small" label={label} disabled={disabled}
      type={numeric ? 'number' : 'text'}
      value={value === null || value === undefined ? '' : String(value)}
      onChange={(e) => onChange(numeric ? (e.target.value === '' ? '' : Number(e.target.value)) : e.target.value)}
      error={Boolean(error)}
      helperText={error ?? (help || defaultHint)}
      slotProps={{
        htmlInput: numeric
          ? {
            min: definition.min ?? undefined,
            max: definition.max ?? undefined,
            step: definition.type === 'int' ? 1 : 'any',
            inputMode: definition.type === 'int' ? 'numeric' : 'decimal',
          }
          : { lang: 'bn' },
      }}
    />
  );
}
