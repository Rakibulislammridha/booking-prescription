// One branch form for both Create and Edit (BRIEF §5.A). `code` is what appears on token slips and invoice
// numbers, so it is upper-cased as it is typed; the slug is what the public booking site puts in the URL.
import { useTranslation } from 'react-i18next';
import type { InertiaFormProps } from '@inertiajs/react';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import FormControlLabel from '@mui/material/FormControlLabel';
import Grid from '@mui/material/Grid';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';

export interface BranchFormData {
  name: string;
  code: string;
  slug: string;
  address: string;
  phone: string;
  email: string;
  is_main: boolean;
  is_active: boolean;
}

interface Props {
  form: InertiaFormProps<BranchFormData>;
  timezone: string;
  /** The very first branch is always the main branch — CreateBranch enforces it, so the switch says so. */
  forcedMain?: boolean;
}

export function BranchForm({ form, timezone, forcedMain = false }: Props) {
  const { t } = useTranslation();
  const { data, errors } = form;

  return (
    <Stack spacing={2}>
      <Card>
        <CardContent>
          <Grid container spacing={2}>
            <Grid size={{ xs: 12, sm: 8 }}>
              <TextField
                label={t('clinic.branches.fields.name')} value={data.name} fullWidth required autoFocus
                onChange={(e) => form.setData('name', e.target.value)}
                error={Boolean(errors.name)} helperText={errors.name}
                slotProps={{ htmlInput: { lang: 'bn', maxLength: 160 } }}
              />
            </Grid>
            <Grid size={{ xs: 12, sm: 4 }}>
              <TextField
                label={t('clinic.branches.fields.code')} value={data.code} fullWidth required
                onChange={(e) => form.setData('code', e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 8))}
                error={Boolean(errors.code)} helperText={errors.code ?? t('clinic.branches.fields.code_help')}
                slotProps={{ htmlInput: { maxLength: 8, style: { textTransform: 'uppercase' } } }}
              />
            </Grid>
            <Grid size={{ xs: 12, sm: 6 }}>
              <TextField
                label={t('clinic.branches.fields.slug')} value={data.slug} fullWidth
                onChange={(e) => form.setData('slug', e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''))}
                error={Boolean(errors.slug)} helperText={errors.slug ?? t('clinic.branches.fields.slug_help')}
              />
            </Grid>
            <Grid size={{ xs: 12, sm: 6 }}>
              <TextField
                label={t('clinic.branches.fields.phone')} value={data.phone} fullWidth
                onChange={(e) => form.setData('phone', e.target.value)}
                error={Boolean(errors.phone)} helperText={errors.phone}
                slotProps={{ htmlInput: { inputMode: 'tel', maxLength: 20 } }}
              />
            </Grid>
            <Grid size={{ xs: 12, sm: 6 }}>
              <TextField
                label={t('clinic.branches.fields.email')} value={data.email} fullWidth type="email"
                onChange={(e) => form.setData('email', e.target.value)}
                error={Boolean(errors.email)} helperText={errors.email}
              />
            </Grid>
            <Grid size={{ xs: 12, sm: 6 }}>
              <TextField label={t('clinic.branches.fields.timezone')} value={timezone} fullWidth disabled helperText={t('clinic.branches.fields.timezone_help')} />
            </Grid>
            <Grid size={{ xs: 12 }}>
              <TextField
                label={t('clinic.branches.fields.address')} value={data.address} fullWidth multiline minRows={2}
                onChange={(e) => form.setData('address', e.target.value)}
                error={Boolean(errors.address)} helperText={errors.address}
                slotProps={{ htmlInput: { lang: 'bn' } }}
              />
            </Grid>
          </Grid>
        </CardContent>
      </Card>

      <Card>
        <CardContent>
          <Stack spacing={0.5}>
            <FormControlLabel
              control={<Switch checked={forcedMain || data.is_main} disabled={forcedMain} onChange={(e) => form.setData('is_main', e.target.checked)} />}
              label={t('clinic.branches.fields.is_main')}
            />
            <Typography variant="caption" color="text.secondary">
              {forcedMain ? t('clinic.branches.fields.is_main_forced') : t('clinic.branches.fields.is_main_help')}
            </Typography>
            <FormControlLabel
              control={<Switch checked={data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />}
              label={t('clinic.branches.fields.is_active')}
            />
          </Stack>
        </CardContent>
      </Card>
    </Stack>
  );
}
