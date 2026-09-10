// "Change subdomain". The slug is a hostname: the edit form shows it read-only and this dialog is the one way it
// changes — a new slug checked live, the current one typed back, and the consequences spelled out before the
// button is enabled (the old address dies at once, every staff session on it is orphaned, printed links break).
import { useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogContentText from '@mui/material/DialogContentText';
import DialogTitle from '@mui/material/DialogTitle';
import TextField from '@mui/material/TextField';
import { useSharedProps } from '@shared/inertia';
import { route } from '@shared/routes';
import { SlugField } from './SlugField';
import type { ConsoleTenantDetail, SlugAvailability } from './types';

export interface ChangeSlugDialogProps {
  open: boolean;
  tenant: ConsoleTenantDetail;
  centralDomain: string;
  onClose: () => void;
}

export function ChangeSlugDialog({ open, tenant, centralDomain, onClose }: ChangeSlugDialogProps) {
  const { t } = useTranslation();
  const shared = useSharedProps();
  const form = useForm({ slug: '', confirm_slug: '' });
  const [availability, setAvailability] = useState<SlugAvailability>('idle');

  const submit = (event: FormEvent): void => {
    event.preventDefault();
    form.post(route('super.tenants.slug', { tenant: tenant.public_id }), { preserveScroll: true });
  };

  const ready = availability === 'available' && form.data.slug !== tenant.slug && form.data.confirm_slug.trim() === tenant.slug;

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
      <Box component="form" onSubmit={submit} noValidate data-testid="rename-form">
        <DialogTitle>{t('super.tenants.rename.title')}</DialogTitle>
        <DialogContent sx={{ display: 'grid', gap: 2, pt: 1 }}>
          <Alert severity="warning">{t('super.tenants.rename.body', { host: tenant.host })}</Alert>
          <SlugField value={form.data.slug} onChange={(slug) => form.setData('slug', slug)} centralDomain={centralDomain} error={form.errors.slug} ignore={tenant.public_id} onAvailability={setAvailability} label={t('super.tenants.rename.new_slug')} autoFocus />
          <DialogContentText>{t('super.tenants.rename.confirm_help', { slug: tenant.slug })}</DialogContentText>
          <TextField
            label={t('super.tenants.rename.confirm_slug')}
            value={form.data.confirm_slug}
            onChange={(e) => form.setData('confirm_slug', e.target.value)}
            error={Boolean(form.errors.confirm_slug)}
            helperText={form.errors.confirm_slug}
            required
            slotProps={{ htmlInput: { maxLength: 63, spellCheck: false, autoCapitalize: 'none', 'data-testid': 'rename-confirm' } }}
          />
          {shared.errors.domain ? <Alert severity="error">{shared.errors.domain}</Alert> : null}
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose}>{t('super.actions.cancel')}</Button>
          <Button type="submit" color="warning" variant="contained" disabled={form.processing || !ready} data-testid="rename-submit">
            {t('super.tenants.rename.submit')}
          </Button>
        </DialogActions>
      </Box>
    </Dialog>
  );
}
