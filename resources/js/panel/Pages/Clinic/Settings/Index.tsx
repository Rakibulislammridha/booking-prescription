// Clinic/Settings/Index — the registry-driven settings screen (SCHEMA Appendix B) plus the clinic's branding.
//
// Nothing on the left is hand-written: `groups` and `registry` come straight from SettingsRegistry, and each field
// renders itself from its own definition. The branding card on the right writes the central `tenants` row, which is
// where the public booking site gets its name, logo and `--tenant-*` colours.
import { useMemo, useRef, useState, type ChangeEvent, type ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Grid from '@mui/material/Grid';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import UploadIcon from '@mui/icons-material/UploadFile';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { SettingField } from '@panel/Components/Clinic/SettingField';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type { ClinicBranding, SettingDefinition, SettingGroup, SettingValue } from '@shared/types/models';

type Props = PageProps<{
  registry: Record<string, SettingDefinition>;
  groups: SettingGroup[];
  values: Record<string, SettingValue>;
  branding: ClinicBranding;
  can: { manage: boolean };
}>;

interface SettingsFormData {
  values: Record<string, SettingValue>;
}

interface BrandingFormData {
  name: string;
  name_bn: string;
  locale: 'bn' | 'en';
  primary_color: string;
  accent_color: string;
  on_primary_color: string;
  logo: File | null;
  clear_logo: boolean;
}

export default function Index({ registry, groups, values, branding, can }: Props) {
  const { t } = useTranslation();
  const [tab, setTab] = useState(0);
  const logoInput = useRef<HTMLInputElement | null>(null);

  // `values` carries a MASK for every `secret` key (`••••1234`), never the credential. The form must therefore
  // start those fields EMPTY — an empty submit is the server's "keep what is stored" — or a save that nobody
  // meant to touch would write the mask itself into the row.
  const blankSecrets = (from: Record<string, SettingValue>): Record<string, SettingValue> => {
    const out: Record<string, SettingValue> = { ...from };
    for (const [key, definition] of Object.entries(registry)) if (definition.secret) out[key] = '';
    return out;
  };

  const settingsForm = useForm<SettingsFormData>({ values: blankSecrets(values) });
  const brandingForm = useForm<BrandingFormData>({
    name: branding.name,
    name_bn: branding.name_bn ?? '',
    locale: branding.locale,
    primary_color: branding.primary_color ?? '#0f766e',
    accent_color: branding.accent_color ?? '#f59e0b',
    on_primary_color: branding.on_primary_color ?? '#ffffff',
    logo: null,
    clear_logo: false,
  });

  const tabs = useMemo(() => [...groups.map((g) => g.prefix), 'branding'], [groups]);
  const active = tabs[tab] ?? 'branding';
  const errorFor = (key: string): string | undefined => (settingsForm.errors as Record<string, string | undefined>)[`values.${key}`];

  const setValue = (key: string, value: SettingValue): void => settingsForm.setData('values', { ...settingsForm.data.values, [key]: value });

  const saveSettings = (): void => {
    // A secret marked for removal (its local value is `null`) travels in its OWN list. The server treats a blank
    // credential as "unchanged" — it has to, because Laravel turns an untouched password box into `null` before
    // the request is read — so deleting one needs a signal an empty input cannot forge.
    settingsForm.transform((data) => {
      const values: Record<string, SettingValue> = {};
      const remove: string[] = [];
      for (const [key, value] of Object.entries(data.values)) {
        if (registry[key]?.secret && value === null) remove.push(key);
        else values[key] = value;
      }
      return { values, remove };
    });
    settingsForm.put(route('panel.clinic.settings.update'), { preserveScroll: true });
  };
  const saveBranding = (): void => {
    // A multipart body cannot be a real PUT, so Laravel's method spoofing carries it (Inertia's documented pattern
    // for file uploads on a PUT route).
    brandingForm.transform((data) => ({ ...data, _method: 'put' }));
    brandingForm.post(route('panel.clinic.branding.update'), { forceFormData: true, preserveScroll: true });
  };
  const pickLogo = (event: ChangeEvent<HTMLInputElement>): void => {
    const file = event.target.files?.[0] ?? null;
    event.target.value = '';
    brandingForm.setData((current) => ({ ...current, logo: file, clear_logo: false }));
  };

  return (
    <Stack spacing={2}>
      <Tabs value={tab} onChange={(_, value) => setTab(Number(value))} variant="scrollable" scrollButtons="auto" aria-label={t('clinic.settings.title')}>
        {groups.map((group) => <Tab key={group.prefix} label={t(`clinic.settings.group.${group.prefix}`, { defaultValue: group.prefix })} />)}
        <Tab label={t('clinic.settings.group.branding')} />
      </Tabs>

      {active !== 'branding' ? (
        <Card>
          <CardContent>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 2, maxWidth: 720 }}>
              {t(`clinic.settings.group_help.${active}`, { defaultValue: t('clinic.settings.registry_note') })}
            </Typography>
            <Grid container spacing={2}>
              {(groups.find((g) => g.prefix === active)?.keys ?? []).map((key) => {
                const definition = registry[key];
                if (!definition) return null;
                return (
                  <Grid key={key} size={{ xs: 12, sm: 6, md: 4 }}>
                    <SettingField
                      settingKey={key}
                      definition={definition}
                      value={settingsForm.data.values[key] ?? null}
                      mask={definition.secret ? String(values[key] ?? '') : undefined}
                      error={errorFor(key)}
                      disabled={!can.manage}
                      onChange={(value) => setValue(key, value)}
                    />
                  </Grid>
                );
              })}
            </Grid>
            {can.manage ? (
              <Stack direction="row" spacing={1} sx={{ mt: 3 }}>
                <Button variant="contained" onClick={saveSettings} disabled={settingsForm.processing}>{t('common.actions.save')}</Button>
                <Button onClick={() => settingsForm.setData('values', blankSecrets(values))} disabled={settingsForm.processing}>{t('common.actions.cancel')}</Button>
              </Stack>
            ) : null}
          </CardContent>
        </Card>
      ) : (
        <Card>
          <CardContent>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 2, maxWidth: 720 }}>{t('clinic.settings.branding_help')}</Typography>
            <Grid container spacing={2}>
              <Grid size={{ xs: 12, sm: 6 }}>
                <TextField
                  label={t('clinic.settings.branding.name')} value={brandingForm.data.name} required fullWidth
                  onChange={(e) => brandingForm.setData('name', e.target.value)}
                  error={Boolean(brandingForm.errors.name)} helperText={brandingForm.errors.name ?? t('clinic.settings.branding.name_help')}
                  slotProps={{ htmlInput: { maxLength: 160 } }}
                />
              </Grid>
              <Grid size={{ xs: 12, sm: 6 }}>
                <TextField
                  label={t('clinic.settings.branding.name_bn')} value={brandingForm.data.name_bn} fullWidth
                  onChange={(e) => brandingForm.setData('name_bn', e.target.value)}
                  error={Boolean(brandingForm.errors.name_bn)} helperText={brandingForm.errors.name_bn}
                  slotProps={{ htmlInput: { lang: 'bn', maxLength: 200 } }}
                />
              </Grid>
              <Grid size={{ xs: 12, sm: 4 }}>
                <TextField
                  select label={t('clinic.settings.branding.locale')} value={brandingForm.data.locale} fullWidth
                  onChange={(e) => brandingForm.setData('locale', e.target.value === 'en' ? 'en' : 'bn')}
                  helperText={t('clinic.settings.branding.locale_help')}
                >
                  <MenuItem value="bn">বাংলা</MenuItem>
                  <MenuItem value="en">English</MenuItem>
                </TextField>
              </Grid>
              <Grid size={{ xs: 12, sm: 8 }}>
                <TextField label={t('clinic.settings.branding.timezone')} value={branding.timezone} fullWidth disabled helperText={t('clinic.settings.branding.timezone_help')} />
              </Grid>
              {([
                ['primary_color', t('clinic.settings.branding.primary')],
                ['accent_color', t('clinic.settings.branding.accent')],
                ['on_primary_color', t('clinic.settings.branding.on_primary')],
              ] as const).map(([key, label]) => (
                <Grid key={key} size={{ xs: 12, sm: 4 }}>
                  <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
                    <Box
                      component="input" type="color" aria-label={label}
                      value={brandingForm.data[key]}
                      onChange={(e: ChangeEvent<HTMLInputElement>) => brandingForm.setData(key, e.target.value)}
                      sx={{ width: 44, height: 40, p: 0, border: 0, background: 'none', cursor: 'pointer' }}
                    />
                    <TextField
                      label={label} value={brandingForm.data[key]} fullWidth size="small"
                      onChange={(e) => brandingForm.setData(key, e.target.value)}
                      error={Boolean(brandingForm.errors[key])} helperText={brandingForm.errors[key]}
                      slotProps={{ htmlInput: { maxLength: 9, placeholder: '#0f766e' } }}
                    />
                  </Stack>
                </Grid>
              ))}
              <Grid size={{ xs: 12 }}>
                <Stack direction="row" spacing={2} sx={{ alignItems: 'center' }}>
                  {branding.logo_url && !brandingForm.data.clear_logo
                    ? <Box component="img" src={branding.logo_url} alt="" sx={{ height: 48, maxWidth: 180, objectFit: 'contain' }} />
                    : <Typography variant="caption" color="text.secondary">{t('clinic.settings.branding.no_logo')}</Typography>}
                  <Button size="small" variant="outlined" startIcon={<UploadIcon />} onClick={() => logoInput.current?.click()}>
                    {t('clinic.settings.branding.upload_logo')}
                  </Button>
                  {branding.logo_url ? (
                    <Button size="small" onClick={() => brandingForm.setData((c) => ({ ...c, clear_logo: !c.clear_logo, logo: null }))}>
                      {brandingForm.data.clear_logo ? t('clinic.settings.branding.keep_logo') : t('clinic.settings.branding.remove_logo')}
                    </Button>
                  ) : null}
                  {brandingForm.data.logo ? <Typography variant="caption">{brandingForm.data.logo.name}</Typography> : null}
                  <input ref={logoInput} type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml" hidden onChange={pickLogo} />
                </Stack>
                {brandingForm.errors.logo ? <Alert severity="error" sx={{ mt: 1 }}>{brandingForm.errors.logo}</Alert> : null}
              </Grid>
            </Grid>
            {can.manage ? (
              <Stack direction="row" spacing={1} sx={{ mt: 3 }}>
                <Button variant="contained" onClick={saveBranding} disabled={brandingForm.processing}>{t('common.actions.save')}</Button>
              </Stack>
            ) : null}
          </CardContent>
        </Card>
      )}
    </Stack>
  );
}

Index.layout = (page: ReactNode) => <PanelLayout title="clinic.settings.title">{page}</PanelLayout>;
