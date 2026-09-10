// Move a clinic to another plan. The dialog says the one thing an operator must know before pressing the
// button: entitlements change NOW, the price changes at the NEXT renewal — there is no proration (saas.md §1).
import { useEffect, type FormEvent } from 'react';
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
import MenuItem from '@mui/material/MenuItem';
import TextField from '@mui/material/TextField';
import { formatBdt } from '@shared/format/money';
import { getLocale } from '@shared/locale';
import type { BillingCycleValue, SuperPlan } from '../types';

export interface ChangePlanDialogProps {
  open: boolean;
  tenantName: string;
  current: { plan_code: string; billing_cycle: BillingCycleValue } | null;
  plans: SuperPlan[];
  /** Where to POST `{ plan, billing_cycle }`. */
  action: string;
  onClose: () => void;
}

export function ChangePlanDialog({ open, tenantName, current, plans, action, onClose }: ChangePlanDialogProps) {
  const { t } = useTranslation();
  const locale = getLocale();
  const form = useForm<{ plan: string; billing_cycle: BillingCycleValue }>({ plan: current?.plan_code ?? '', billing_cycle: current?.billing_cycle ?? 'monthly' });

  useEffect(() => {
    if (!open) return;
    form.setData({ plan: current?.plan_code ?? '', billing_cycle: current?.billing_cycle ?? 'monthly' });
    form.clearErrors();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, current?.plan_code, current?.billing_cycle]);

  const selectable = plans.filter((plan) => !plan.archived_at || plan.code === current?.plan_code);
  const target = selectable.find((plan) => plan.code === form.data.plan);
  const nextPrice = target === undefined ? null : (form.data.billing_cycle === 'yearly' ? target.price_yearly_paisa : target.price_monthly_paisa);
  const unchanged = current !== null && form.data.plan === current.plan_code && form.data.billing_cycle === current.billing_cycle;

  const submit = (event: FormEvent): void => {
    event.preventDefault();
    if (form.data.plan === '' || unchanged) return;
    form.post(action, { preserveScroll: true, onSuccess: onClose });
  };

  return (
    <Dialog open={open} onClose={form.processing ? undefined : onClose} fullWidth maxWidth="sm">
      <Box component="form" onSubmit={submit} noValidate>
        <DialogTitle>{t('super.billing.change_plan_title', { clinic: tenantName })}</DialogTitle>
        <DialogContent sx={{ display: 'grid', gap: 2, pt: 1 }}>
          <DialogContentText>{t('super.billing.change_plan_body')}</DialogContentText>
          <TextField select label={t('super.tenants.change_plan')} value={form.data.plan} onChange={(e) => form.setData('plan', e.target.value)} error={Boolean(form.errors.plan)} helperText={form.errors.plan} autoFocus>
            {selectable.map((plan) => (
              <MenuItem key={plan.code} value={plan.code}>
                {plan.name}{plan.is_addon ? ` · ${t('super.plans.addon')}` : ''} · {formatBdt(plan.price_monthly_paisa, locale)}
              </MenuItem>
            ))}
          </TextField>
          <TextField select label={t('super.tenants.field.cycle')} value={form.data.billing_cycle} onChange={(e) => form.setData('billing_cycle', e.target.value as BillingCycleValue)} error={Boolean(form.errors.billing_cycle)} helperText={form.errors.billing_cycle}>
            <MenuItem value="monthly">{t('super.cycle.monthly')}</MenuItem>
            <MenuItem value="yearly">{t('super.cycle.yearly')}</MenuItem>
          </TextField>
          {nextPrice !== null ? (
            <Alert severity="info">{t('super.billing.change_plan_price', { amount: formatBdt(nextPrice, locale) })}</Alert>
          ) : null}
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose} disabled={form.processing}>{t('super.actions.cancel')}</Button>
          <Button type="submit" variant="contained" disabled={form.processing || form.data.plan === '' || unchanged}>{t('super.tenants.apply_plan')}</Button>
        </DialogActions>
      </Box>
    </Dialog>
  );
}
