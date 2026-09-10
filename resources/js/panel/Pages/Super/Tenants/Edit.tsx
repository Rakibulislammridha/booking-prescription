// Editing a clinic's identity from the console: names, the primary contact, locale and timezone, the public-site
// branding, and the platform's own notes. The address is shown but not editable here — it is a hostname, and it
// changes through the "Change subdomain" dialog with its own confirmation and audit row.
import { useState, type FormEvent, type ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Autocomplete from '@mui/material/Autocomplete';
import Avatar from '@mui/material/Avatar';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Checkbox from '@mui/material/Checkbox';
import FormControlLabel from '@mui/material/FormControlLabel';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import UploadFileIcon from '@mui/icons-material/UploadFile';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { SuperNav } from '@panel/Components/Super/SuperNav';
import { ChangeSlugDialog } from '@panel/Components/Super/Tenants/ChangeSlugDialog';
import type { ConsoleTenantDetail } from '@panel/Components/Super/Tenants/types';
import { useSharedProps } from '@shared/inertia';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{
  tenant: ConsoleTenantDetail;
  central_domain: string;
  timezones: string[];
}>;

interface EditForm {
  name: string;
  name_bn: string;
  owner_name: string;
  owner_email: string;
  owner_mobile: string;
  locale: 'bn' | 'en';
  timezone: string;
  primary_color: string;
  accent_color: string;
  logo: File | null;
  clear_logo: boolean;
  notes: string;
}

function ColourField({ label, value, onChange, error }: { label: string; value: string; onChange: (v: string) => void; error?: string }) {
  return (
    <Stack direction="row" spacing={1} sx={{ alignItems: 'flex-start' }}>
      <Box component="input" type="color" value={value === '' ? '#0f766e' : value} onChange={(e) => onChange(e.target.value)} aria-label={label} sx={{ width: 44, height: 40, p: 0, border: 1, borderColor: 'divider', borderRadius: 1, bgcolor: 'transparent', cursor: 'pointer', mt: 0.5 }} />
      <TextField label={label} value={value} onChange={(e) => onChange(e.target.value)} error={Boolean(error)} helperText={error} fullWidth slotProps={{ htmlInput: { maxLength: 9, spellCheck: false, style: { fontFamily: 'monospace' } } }} />
    </Stack>
  );
}

export default function Edit({ tenant, central_domain, timezones }: Props) {
  const { t } = useTranslation();
  const shared = useSharedProps();
  const [slugOpen, setSlugOpen] = useState(false);
  const [preview, setPreview] = useState<string | null>(null);
  const form = useForm<EditForm>({
    name: tenant.name,
    name_bn: tenant.branding.name_bn ?? '',
    owner_name: tenant.owner.name ?? '',
    owner_email: tenant.owner.email ?? '',
    owner_mobile: tenant.owner.mobile ?? '',
    locale: tenant.locale === 'en' ? 'en' : 'bn',
    timezone: tenant.timezone,
    primary_color: tenant.branding.primary_color ?? '',
    accent_color: tenant.branding.accent_color ?? '',
    logo: null,
    clear_logo: false,
    notes: tenant.platform_notes ?? '',
  });

  const onLogo = (file: File | null): void => {
    form.setData((data) => ({ ...data, logo: file, clear_logo: false }));
    setPreview(file ? URL.createObjectURL(file) : null);
  };

  const submit = (event: FormEvent): void => {
    event.preventDefault();
    // A multipart body cannot travel as PUT through PHP, so it is POST + `_method` (Laravel's own spoofing).
    form.transform((data) => ({ ...data, _method: 'put' }));
    form.post(route('super.tenants.update', { tenant: tenant.public_id }), { forceFormData: true });
  };

  const logoShown = form.data.clear_logo ? null : (preview ?? tenant.branding.logo_url);

  return (
    <Box>
      <SuperNav />
      <Box component="form" onSubmit={submit} noValidate data-testid="edit-tenant-form">
        <Stack spacing={2}>
          <Stack direction="row" spacing={1} sx={{ alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap' }} useFlexGap>
            <Box>
              <Typography variant="h5" component="h2" lang="bn">{t('super.tenants.edit_title', { name: tenant.name })}</Typography>
              <Typography variant="body2" color="text.secondary" sx={{ fontFamily: 'monospace' }}>{tenant.host}</Typography>
            </Box>
            <Button component={RouterLink} href={route('super.tenants.show', { tenant: tenant.public_id })} color="inherit">{t('super.tenants.back_to_clinic')}</Button>
          </Stack>

          <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', lg: '1fr 1fr' } }}>
            <Card variant="outlined">
              <CardContent sx={{ display: 'grid', gap: 2 }}>
                <Typography variant="subtitle1" component="h3">{t('super.tenants.form.section.clinic')}</Typography>
                <TextField label={t('super.tenants.form.name')} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} error={Boolean(form.errors.name)} helperText={form.errors.name} required slotProps={{ htmlInput: { maxLength: 160, 'data-testid': 'name-input' } }} />
                <TextField label={t('super.tenants.form.name_bn')} value={form.data.name_bn} onChange={(e) => form.setData('name_bn', e.target.value)} error={Boolean(form.errors.name_bn)} helperText={form.errors.name_bn ?? t('super.tenants.form.name_bn_help')} slotProps={{ htmlInput: { maxLength: 200, lang: 'bn' } }} />
                <TextField label={t('super.tenants.form.slug')} value={tenant.slug} disabled helperText={t('super.tenants.form.slug_immutable', { host: tenant.host })} slotProps={{ htmlInput: { 'data-testid': 'slug-readonly' } }} />
                <Box>
                  <Button variant="outlined" color="warning" onClick={() => setSlugOpen(true)} data-testid="change-slug">{t('super.tenants.rename.open')}</Button>
                </Box>
                <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
                  <TextField select label={t('super.tenants.form.locale')} value={form.data.locale} onChange={(e) => form.setData('locale', e.target.value as 'bn' | 'en')} fullWidth>
                    <MenuItem value="bn">বাংলা</MenuItem>
                    <MenuItem value="en">English</MenuItem>
                  </TextField>
                  <Autocomplete
                    options={timezones.includes(form.data.timezone) ? timezones : [form.data.timezone, ...timezones]}
                    value={form.data.timezone}
                    onChange={(_e, value) => form.setData('timezone', value ?? 'Asia/Dhaka')}
                    fullWidth
                    disableClearable
                    renderInput={(params) => <TextField {...params} label={t('super.tenants.form.timezone')} error={Boolean(form.errors.timezone)} helperText={form.errors.timezone} />}
                  />
                </Stack>
              </CardContent>
            </Card>

            <Card variant="outlined">
              <CardContent sx={{ display: 'grid', gap: 2 }}>
                <Typography variant="subtitle1" component="h3">{t('super.tenants.form.section.contact')}</Typography>
                <TextField label={t('super.tenants.form.owner_name')} value={form.data.owner_name} onChange={(e) => form.setData('owner_name', e.target.value)} error={Boolean(form.errors.owner_name)} helperText={form.errors.owner_name} required slotProps={{ htmlInput: { maxLength: 160, lang: 'bn' } }} />
                <TextField label={t('super.tenants.form.owner_email')} type="email" value={form.data.owner_email} onChange={(e) => form.setData('owner_email', e.target.value)} error={Boolean(form.errors.owner_email)} helperText={form.errors.owner_email} required slotProps={{ htmlInput: { maxLength: 255, 'data-testid': 'owner-email' } }} />
                <TextField label={t('super.tenants.form.owner_mobile')} value={form.data.owner_mobile} onChange={(e) => form.setData('owner_mobile', e.target.value)} error={Boolean(form.errors.owner_mobile)} helperText={form.errors.owner_mobile ?? t('super.tenants.form.mobile_help')} required slotProps={{ htmlInput: { maxLength: 20, inputMode: 'tel' } }} />
              </CardContent>
            </Card>

            <Card variant="outlined">
              <CardContent sx={{ display: 'grid', gap: 2 }}>
                <Typography variant="subtitle1" component="h3">{t('super.tenants.form.section.branding')}</Typography>
                <Stack direction="row" spacing={2} sx={{ alignItems: 'center' }}>
                  <Avatar variant="rounded" src={logoShown ?? undefined} sx={{ width: 72, height: 72, bgcolor: 'action.hover', color: 'text.secondary' }}>{tenant.name.slice(0, 1)}</Avatar>
                  <Stack spacing={1}>
                    <Button component="label" variant="outlined" startIcon={<UploadFileIcon />}>
                      {t('super.tenants.form.logo')}
                      <input type="file" hidden accept="image/png,image/jpeg,image/webp,image/svg+xml" onChange={(e) => onLogo(e.target.files?.[0] ?? null)} data-testid="logo-input" />
                    </Button>
                    <Typography variant="caption" color={form.errors.logo ? 'error' : 'text.secondary'}>{form.errors.logo ?? t('super.tenants.form.logo_help')}</Typography>
                    {tenant.branding.logo_url ? (
                      <FormControlLabel control={<Checkbox checked={form.data.clear_logo} onChange={(e) => form.setData((d) => ({ ...d, clear_logo: e.target.checked, logo: e.target.checked ? null : d.logo }))} />} label={<Typography variant="body2">{t('super.tenants.form.clear_logo')}</Typography>} />
                    ) : null}
                  </Stack>
                </Stack>
                <ColourField label={t('super.tenants.form.primary_color')} value={form.data.primary_color} onChange={(v) => form.setData('primary_color', v)} error={form.errors.primary_color} />
                <ColourField label={t('super.tenants.form.accent_color')} value={form.data.accent_color} onChange={(v) => form.setData('accent_color', v)} error={form.errors.accent_color} />
              </CardContent>
            </Card>

            <Card variant="outlined">
              <CardContent sx={{ display: 'grid', gap: 2 }}>
                <Typography variant="subtitle1" component="h3">{t('super.tenants.form.section.notes')}</Typography>
                <TextField label={t('super.tenants.form.notes')} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} error={Boolean(form.errors.notes)} helperText={form.errors.notes ?? t('super.tenants.form.notes_help')} multiline minRows={5} slotProps={{ htmlInput: { maxLength: 5000, 'data-testid': 'notes-input' } }} />
              </CardContent>
            </Card>
          </Box>

          {shared.errors.domain ? <Alert severity="error">{shared.errors.domain}</Alert> : null}

          <Stack direction="row" spacing={1} sx={{ justifyContent: 'flex-end' }}>
            <Button component={RouterLink} href={route('super.tenants.show', { tenant: tenant.public_id })} color="inherit">{t('super.actions.cancel')}</Button>
            <Button type="submit" variant="contained" size="large" disabled={form.processing} data-testid="edit-submit">{t('super.actions.save')}</Button>
          </Stack>
        </Stack>
      </Box>

      <ChangeSlugDialog open={slugOpen} tenant={tenant} centralDomain={central_domain} onClose={() => setSlugOpen(false)} />
    </Box>
  );
}

Edit.layout = (page: ReactNode) => <PanelLayout title="super.tenants.show_title">{page}</PanelLayout>;
