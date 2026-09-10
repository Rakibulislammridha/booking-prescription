// The end of a clinic, in two steps that cannot be collapsed into one click.
//
// Step one queues the churn export (BRIEF §5.N) and is refused while a platform invoice is unsettled. Step two
// opens only when a COMPLETED export younger than the server's limit exists, and asks for the slug typed back.
// The server re-checks all of it (`DeleteTenant`); this card only makes the state of each guard visible.
import { useState, type FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Checkbox from '@mui/material/Checkbox';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogContentText from '@mui/material/DialogContentText';
import DialogTitle from '@mui/material/DialogTitle';
import FormControlLabel from '@mui/material/FormControlLabel';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import DeleteForeverIcon from '@mui/icons-material/DeleteForever';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { useSharedProps } from '@shared/inertia';
import { route } from '@shared/routes';
import type { ConsoleTenantDetail, DeletionState } from './types';

export interface DeleteTenantCardProps {
  tenant: ConsoleTenantDetail;
  deletion: DeletionState;
}

function DeleteDialog({ open, tenant, onClose }: { open: boolean; tenant: ConsoleTenantDetail; onClose: () => void }) {
  const { t } = useTranslation();
  const shared = useSharedProps();
  const form = useForm({ slug: '', acknowledge: false });

  const submit = (event: FormEvent): void => {
    event.preventDefault();
    form.delete(route('super.tenants.destroy', { tenant: tenant.public_id }), { preserveScroll: true });
  };

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
      <Box component="form" onSubmit={submit} noValidate data-testid="delete-form">
        <DialogTitle>{t('super.tenants.delete.dialog_title', { name: tenant.name })}</DialogTitle>
        <DialogContent sx={{ display: 'grid', gap: 2, pt: 1 }}>
          <Alert severity="error">{t('super.tenants.delete.dialog_body', { schema: tenant.schema_name })}</Alert>
          <DialogContentText>{t('super.tenants.delete.type_slug', { slug: tenant.slug })}</DialogContentText>
          <TextField
            label={t('super.tenants.form.slug')}
            value={form.data.slug}
            onChange={(e) => form.setData('slug', e.target.value)}
            error={Boolean(form.errors.slug)}
            helperText={form.errors.slug}
            required
            autoFocus
            slotProps={{ htmlInput: { maxLength: 63, spellCheck: false, autoCapitalize: 'none', 'data-testid': 'delete-slug' } }}
          />
          <FormControlLabel
            control={<Checkbox checked={form.data.acknowledge} onChange={(e) => form.setData('acknowledge', e.target.checked)} data-testid="delete-acknowledge" />}
            label={t('super.tenants.delete.acknowledge')}
          />
          {form.errors.acknowledge ? <Typography variant="caption" color="error">{form.errors.acknowledge}</Typography> : null}
          {shared.errors.domain ? <Alert severity="error">{shared.errors.domain}</Alert> : null}
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose}>{t('super.actions.cancel')}</Button>
          <Button type="submit" color="error" variant="contained" startIcon={<DeleteForeverIcon />} disabled={form.processing || form.data.slug.trim() !== tenant.slug || !form.data.acknowledge} data-testid="delete-confirm">
            {t('super.tenants.delete.confirm')}
          </Button>
        </DialogActions>
      </Box>
    </Dialog>
  );
}

export function DeleteTenantCard({ tenant, deletion }: DeleteTenantCardProps) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [open, setOpen] = useState(false);
  const [preparing, setPreparing] = useState(false);
  const blocked = deletion.unsettled_invoices > 0;
  const ready = deletion.export !== null;

  const prepare = (): void => {
    setPreparing(true);
    router.post(route('super.tenants.deletion.prepare', { tenant: tenant.public_id }), {}, { preserveScroll: true, onFinish: () => setPreparing(false) });
  };

  return (
    <Card variant="outlined" sx={{ borderColor: 'error.main' }} data-testid="delete-card">
      <CardContent>
        <Typography variant="subtitle1" component="h3" color="error.main">{t('super.tenants.delete.title')}</Typography>
        <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>{t('super.tenants.delete.intro')}</Typography>

        {blocked ? <Alert severity="warning" sx={{ mb: 2 }}>{t('super.tenants.delete.blocked_invoices', { count: deletion.unsettled_invoices })}</Alert> : null}

        <Stack spacing={2}>
          <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} sx={{ alignItems: { md: 'center' } }}>
            <Box sx={{ flexGrow: 1 }}>
              <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
                <Chip size="small" label="1" />
                <Typography variant="body2" sx={{ fontWeight: 600 }}>{t('super.tenants.delete.step1')}</Typography>
                {ready ? <Chip size="small" color="success" label={t('super.tenants.delete.export_ready', { date: formatDhaka(deletion.export?.completed_at ?? '', 'D MMM YYYY, h:mm a', locale) })} /> : <Chip size="small" color="default" variant="outlined" label={t('super.tenants.delete.export_missing', { hours: deletion.export_max_age_hours })} />}
              </Stack>
              <Typography variant="caption" color="text.secondary">{t('super.tenants.delete.step1_help')}</Typography>
            </Box>
            <Button variant="outlined" color="error" onClick={prepare} disabled={blocked || preparing} data-testid="delete-prepare">
              {t('super.tenants.delete.prepare')}
            </Button>
          </Stack>

          <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} sx={{ alignItems: { md: 'center' } }}>
            <Box sx={{ flexGrow: 1 }}>
              <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
                <Chip size="small" label="2" />
                <Typography variant="body2" sx={{ fontWeight: 600 }}>{t('super.tenants.delete.step2')}</Typography>
              </Stack>
              <Typography variant="caption" color="text.secondary">{t('super.tenants.delete.step2_help')}</Typography>
            </Box>
            <Button variant="contained" color="error" startIcon={<DeleteForeverIcon />} onClick={() => setOpen(true)} disabled={blocked || !ready} data-testid="delete-open">
              {t('super.tenants.delete.open')}
            </Button>
          </Stack>
        </Stack>
      </CardContent>
      <DeleteDialog open={open} tenant={tenant} onClose={() => setOpen(false)} />
    </Card>
  );
}
