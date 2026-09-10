// The two lifecycle actions that used to fire on a bare click. Reactivating a clinic puts it back on the internet
// — with unpaid invoices, if `force` is ticked — and changing a plan moves its entitlements at once and its price
// at the next renewal; both deserve one sentence and a button, like suspend and cancel already had.
import { useEffect, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Checkbox from '@mui/material/Checkbox';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogContentText from '@mui/material/DialogContentText';
import DialogTitle from '@mui/material/DialogTitle';
import FormControlLabel from '@mui/material/FormControlLabel';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useSharedProps } from '@shared/inertia';
import { route } from '@shared/routes';
import type { SuperPlan } from '@panel/Components/Super/types';
import type { ConsoleTenantDetail } from './types';

export function ReactivateDialog({ open, tenant, onClose }: { open: boolean; tenant: ConsoleTenantDetail; onClose: () => void }) {
  const { t } = useTranslation();
  const form = useForm({ reason: '', force: false });
  const arrears = tenant.arrears_invoices > 0;

  useEffect(() => { if (open) { form.setData({ reason: '', force: false }); form.clearErrors(); } /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [open]);

  const submit = (event: FormEvent): void => {
    event.preventDefault();
    form.post(route('super.tenants.reactivate', { tenant: tenant.public_id }), { preserveScroll: true, onSuccess: onClose });
  };

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
      <Box component="form" onSubmit={submit} noValidate>
        <DialogTitle>{t('super.tenants.reactivate_title')}</DialogTitle>
        <DialogContent sx={{ display: 'grid', gap: 2, pt: 1 }}>
          <DialogContentText>{t('super.tenants.reactivate_body', { name: tenant.name })}</DialogContentText>
          {arrears ? <Alert severity="warning">{t('super.tenants.reactivate_arrears', { count: tenant.arrears_invoices })}</Alert> : null}
          <TextField
            label={t('super.tenants.reason')}
            value={form.data.reason}
            onChange={(e) => form.setData('reason', e.target.value)}
            error={Boolean(form.errors.reason)}
            helperText={form.errors.reason ?? t('super.tenants.reason_help')}
            autoFocus
            slotProps={{ htmlInput: { maxLength: 255 } }}
          />
          <FormControlLabel
            control={<Checkbox checked={form.data.force} onChange={(e) => form.setData('force', e.target.checked)} />}
            label={<Typography variant="body2">{t('super.tenants.force_help')}</Typography>}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose}>{t('super.actions.cancel')}</Button>
          <Button type="submit" color="success" variant="contained" disabled={form.processing || (arrears && !form.data.force)}>
            {t('super.tenants.reactivate')}
          </Button>
        </DialogActions>
      </Box>
    </Dialog>
  );
}

export interface ChangePlanDialogProps {
  tenant: ConsoleTenantDetail;
  /** null closes the dialog; otherwise the plan and cycle the operator picked on the overview. */
  choice: { plan: string; billing_cycle: 'monthly' | 'yearly' } | null;
  plans: SuperPlan[];
  onClose: () => void;
}

export function ChangePlanDialog({ tenant, choice, plans, onClose }: ChangePlanDialogProps) {
  const { t } = useTranslation();
  const shared = useSharedProps();
  const form = useForm({ plan: '', billing_cycle: 'monthly' as 'monthly' | 'yearly' });
  const open = choice !== null;
  const plan = plans.find((p) => p.code === choice?.plan);

  useEffect(() => {
    if (choice !== null) { form.setData({ plan: choice.plan, billing_cycle: choice.billing_cycle }); form.clearErrors(); }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [choice]);

  const submit = (event: FormEvent): void => {
    event.preventDefault();
    form.post(route('super.tenants.plan', { tenant: tenant.public_id }), { preserveScroll: true, onSuccess: onClose });
  };

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
      <Box component="form" onSubmit={submit} noValidate>
        <DialogTitle>{t('super.tenants.plan_title')}</DialogTitle>
        <DialogContent sx={{ display: 'grid', gap: 2, pt: 1 }}>
          <DialogContentText>
            {t('super.tenants.plan_body', {
              name: tenant.name,
              from: tenant.subscription?.plan_name ?? '—',
              to: plan?.name ?? choice?.plan ?? '',
              cycle: t(`super.cycle.${choice?.billing_cycle ?? 'monthly'}`),
            })}
          </DialogContentText>
          {form.errors.plan ? <Alert severity="error">{form.errors.plan}</Alert> : null}
          {shared.errors.domain ? <Alert severity="error">{shared.errors.domain}</Alert> : null}
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose}>{t('super.actions.cancel')}</Button>
          <Button type="submit" variant="contained" disabled={form.processing}>{t('super.tenants.apply_plan')}</Button>
        </DialogActions>
      </Box>
    </Dialog>
  );
}
