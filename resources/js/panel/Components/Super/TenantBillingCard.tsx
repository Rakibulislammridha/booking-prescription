// One clinic's money, on its own page in the console: the subscription and what it costs, the arrears, what the
// next dunning run would do to it, its invoices and its payments — with the four things an operator does about
// any of that (change plan, raise an invoice, record a payment, void a mistake).
//
// Drop-in for the tenant detail page:
//
//   <TenantBillingCard tenant={{ public_id, name }} billing={billing} plans={plans} />
//
// `billing` is `App\Domain\SaaS\Queries\BillingTenantPanel::for($tenant)` passed straight through; `plans` is
// `PlanCatalog::all()`. Every button posts to a `super.billing.*` route, so the card depends on nothing the page
// itself registers.
import { useState } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Divider from '@mui/material/Divider';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { StatusChip } from './StatusChip';
import { SuperTable, type SuperColumn } from './SuperTable';
import { ChangePlanDialog } from './Billing/ChangePlanDialog';
import { ConfirmActionDialog } from './Billing/ConfirmActionDialog';
import { InvoiceTable } from './Billing/InvoiceTable';
import { PAYMENT_METHOD_LABELS, PaymentStatusChip } from './Billing/InvoiceStatusChip';
import { RecordPaymentDialog } from './Billing/RecordPaymentDialog';
import { VoidInvoiceDialog } from './Billing/VoidInvoiceDialog';
import type { BillingInvoiceRow, BillingPaymentRow, TenantBilling } from './Billing/types';
import type { SuperPlan } from './types';
import { formatBdt } from '@shared/format/money';
import { formatNumber } from '@shared/format/number';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { hasRoute, route } from '@shared/routes';
import type { Locale } from '@shared/types/shared-props';

export interface TenantBillingCardProps {
  tenant: { public_id: string; name: string };
  billing: TenantBilling;
  plans: SuperPlan[];
}

const DATE = 'D MMM YYYY';

function when(iso: string | null, locale: Locale): string {
  return iso === null ? '—' : formatDhaka(iso, DATE, locale);
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <Box sx={{ minWidth: 0 }}>
      <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{label}</Typography>
      <Typography variant="body2" component="div" sx={{ wordBreak: 'break-word' }}>{children}</Typography>
    </Box>
  );
}

export function TenantBillingCard({ tenant, billing, plans }: TenantBillingCardProps) {
  const { t } = useTranslation();
  const locale = getLocale();
  const n = (value: number): string => formatNumber(value, locale);
  const [planOpen, setPlanOpen] = useState(false);
  const [dunOpen, setDunOpen] = useState(false);
  const [raiseOpen, setRaiseOpen] = useState(false);
  const [paying, setPaying] = useState<BillingInvoiceRow | null>(null);
  const [voiding, setVoiding] = useState<BillingInvoiceRow | null>(null);
  const [busy, setBusy] = useState(false);

  const subscription = billing.subscription;
  const ready = hasRoute('super.billing.invoices.store');

  const post = (url: string, data: Record<string, string | number | boolean | null>, done?: () => void): void => {
    setBusy(true);
    router.post(url, data, { preserveScroll: true, onFinish: () => { setBusy(false); done?.(); } });
  };

  const paymentColumns: SuperColumn<BillingPaymentRow>[] = [
    { key: 'paid_at', label: t('super.billing.column.paid_at'), render: (row) => when(row.paid_at ?? row.created_at, locale) },
    { key: 'invoice', label: t('super.billing.column.number'), render: (row) => <Typography variant="body2" sx={{ fontFamily: 'monospace' }}>{row.invoice?.number ?? '—'}</Typography> },
    { key: 'method', label: t('super.billing.method'), render: (row) => t(PAYMENT_METHOD_LABELS[row.method] ?? row.method) },
    { key: 'status', label: t('super.billing.column.status'), render: (row) => <PaymentStatusChip status={row.status} /> },
    { key: 'reference', label: t('super.billing.reference'), render: (row) => <Typography variant="body2" sx={{ fontFamily: 'monospace' }}>{row.gateway_txn_id ?? '—'}</Typography> },
    { key: 'amount', label: t('super.billing.column.amount'), align: 'right', render: (row) => formatBdt(row.amount_paisa, locale) },
    { key: 'by', label: t('super.billing.column.recorded_by'), render: (row) => row.recorded_by ?? t('super.audit.system') },
  ];

  return (
    <Stack spacing={2} data-testid="tenant-billing-card">
      <Card variant="outlined">
        <CardContent>
          <Stack direction="row" spacing={1} sx={{ alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', mb: 1.5 }} useFlexGap>
            <Box>
              <Typography variant="subtitle1" component="h3">{t('super.tenants.subscription_title')}</Typography>
              <Typography variant="caption" color={billing.arrears_paisa > 0 ? 'error.main' : 'text.secondary'}>
                {t('super.billing.arrears', { amount: formatBdt(billing.arrears_paisa, locale), count: n(billing.arrears_invoices) })}
              </Typography>
            </Box>
            <Stack direction="row" spacing={1} useFlexGap sx={{ flexWrap: 'wrap' }}>
              <Button variant="outlined" size="small" onClick={() => setPlanOpen(true)} disabled={subscription === null || !ready}>{t('super.tenants.apply_plan')}</Button>
              <Button variant="contained" size="small" onClick={() => setRaiseOpen(true)} disabled={subscription === null || !ready}>{t('super.billing.raise_invoice')}</Button>
              {hasRoute('super.billing.invoices.index') ? (
                <Button size="small" component={RouterLink} href={route('super.billing.invoices.index', { tenant: tenant.public_id })}>{t('super.billing.open_ledger')}</Button>
              ) : null}
            </Stack>
          </Stack>

          {subscription === null ? (
            <Alert severity="info">{t('super.tenants.no_subscription')}</Alert>
          ) : (
            <Stack direction="row" spacing={3} sx={{ flexWrap: 'wrap' }} useFlexGap>
              <Field label={t('super.tenants.field.plan')}>{subscription.plan_name}</Field>
              <Field label={t('super.tenants.field.status')}><StatusChip status={subscription.status} /></Field>
              <Field label={t('super.tenants.field.cycle')}>{subscription.billing_cycle === 'yearly' ? t('super.cycle.yearly') : t('super.cycle.monthly')}</Field>
              <Field label={t('super.tenants.field.price')}>{formatBdt(subscription.price_paisa, locale)}</Field>
              <Field label={t('super.tenants.field.period_end')}>{when(subscription.current_period_end, locale)}</Field>
              <Field label={t('super.billing.field.next_invoice')}>{when(billing.next_invoice_at, locale)}</Field>
              <Field label={t('super.tenants.field.trial_end')}>{when(subscription.trial_ends_at, locale)}</Field>
              <Field label={t('super.tenants.field.grace')}>{when(subscription.grace_until, locale)}</Field>
              <Field label={t('super.tenants.field.renewal')}>
                {subscription.cancel_at_period_end ? (
                  <Chip size="small" color="warning" label={t('super.tenants.cancels_at_period_end')} />
                ) : subscription.auto_renew ? t('super.tenants.auto_renew_on') : t('super.tenants.auto_renew_off')}
              </Field>
            </Stack>
          )}

          {billing.addons.length > 0 ? (
            <Stack direction="row" spacing={1} sx={{ mt: 1.5, flexWrap: 'wrap', alignItems: 'center' }} useFlexGap>
              <Typography variant="caption" color="text.secondary">{t('super.tenants.addons')}</Typography>
              {billing.addons.map((addon) => <Chip key={addon.plan_code} size="small" variant="outlined" label={addon.plan_name} />)}
            </Stack>
          ) : null}

          {billing.dunning.length > 0 ? (
            <>
              <Divider sx={{ my: 2 }} />
              <Stack direction="row" spacing={1} sx={{ alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap' }} useFlexGap>
                <Box>
                  <Typography variant="subtitle2">{t('super.billing.dunning.card_title')}</Typography>
                  <Typography variant="caption" color="text.secondary">
                    {billing.dunning.some((d) => d.will_suspend)
                      ? t('super.billing.dunning.will_suspend')
                      : t('super.billing.dunning.will_notify', { count: n(billing.dunning.filter((d) => d.will_notify).length) })}
                  </Typography>
                </Box>
                <Button size="small" color="warning" variant="outlined" onClick={() => setDunOpen(true)} disabled={!hasRoute('super.billing.dunning.run')}>
                  {t('super.billing.dunning.run_now')}
                </Button>
              </Stack>
            </>
          ) : null}
        </CardContent>
      </Card>

      <Card variant="outlined">
        <CardContent sx={{ pb: 0 }}>
          <Typography variant="subtitle1" component="h3">{t('super.billing.title')}</Typography>
        </CardContent>
        <InvoiceTable
          invoices={billing.invoices}
          empty={t('super.billing.empty')}
          onIssue={(invoice) => post(route('super.billing.invoices.issue', { invoice: invoice.public_id }), {})}
          onPay={setPaying}
          onVoid={setVoiding}
        />
      </Card>

      <Card variant="outlined">
        <CardContent sx={{ pb: 0 }}>
          <Typography variant="subtitle1" component="h3">{t('super.billing.payments_title')}</Typography>
        </CardContent>
        <SuperTable columns={paymentColumns} rows={billing.payments} rowKey={(row) => row.public_id} empty={t('super.billing.payments_empty')} label={t('super.billing.payments_title')} />
      </Card>

      <ChangePlanDialog
        open={planOpen}
        tenantName={tenant.name}
        current={subscription === null ? null : { plan_code: subscription.plan_code, billing_cycle: subscription.billing_cycle }}
        plans={plans}
        action={ready ? route('super.billing.subscriptions.plan', { tenant: tenant.public_id }) : ''}
        onClose={() => setPlanOpen(false)}
      />
      <RecordPaymentDialog invoice={paying} action={(invoice) => route('super.billing.invoices.pay', { invoice: invoice.public_id })} onClose={() => setPaying(null)} />
      <VoidInvoiceDialog invoice={voiding} action={(invoice) => route('super.billing.invoices.void', { invoice: invoice.public_id })} onClose={() => setVoiding(null)} />
      <ConfirmActionDialog
        open={raiseOpen}
        title={t('super.billing.raise_title')}
        body={t('super.billing.raise_body', { clinic: tenant.name, amount: formatBdt(subscription?.price_paisa ?? 0, locale) })}
        confirmLabel={t('super.billing.raise_invoice')}
        busy={busy}
        onClose={() => setRaiseOpen(false)}
        onConfirm={() => post(route('super.billing.invoices.store'), { tenant: tenant.public_id, issue: true }, () => setRaiseOpen(false))}
      />
      <ConfirmActionDialog
        open={dunOpen}
        title={t('super.billing.dunning.run_title', { clinic: tenant.name })}
        body={t('super.billing.dunning.run_body')}
        confirmLabel={t('super.billing.dunning.run_now')}
        color="warning"
        busy={busy}
        onClose={() => setDunOpen(false)}
        onConfirm={() => post(route('super.billing.dunning.run', { tenant: tenant.public_id }), {}, () => setDunOpen(false))}
      />
    </Stack>
  );
}
