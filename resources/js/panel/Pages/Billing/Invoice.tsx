// One bill: frozen lines, the payments taken against it, refunds, and the desk actions (BRIEF §5.I).
import { useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Divider from '@mui/material/Divider';
import Grid from '@mui/material/Grid';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import Typography from '@mui/material/Typography';
import PrintIcon from '@mui/icons-material/Print';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { CollectPaymentDialog } from '@panel/Components/Billing/CollectPaymentDialog';
import { DiscountDialog } from '@panel/Components/Billing/DiscountDialog';
import { InvoiceStatusChip } from '@panel/Components/Billing/InvoiceStatusChip';
import { RefundDialog } from '@panel/Components/Billing/RefundDialog';
import { applyCoupon, applyDiscount, collectPayment, issueRefund, newClientEventId } from '@panel/api/billing';
import { isApiError } from '@shared/http';
import { route } from '@shared/routes';
import { formatBdt } from '@shared/format/money';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import type { PageProps } from '@shared/types/inertia';
import type { BillingInvoice, BillingPayment } from '@shared/types/models';

type Props = PageProps<{ invoice: BillingInvoice; gateways: string[]; can: Record<string, boolean> }>;

export default function Invoice({ invoice: initial, can }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [invoice, setInvoice] = useState<BillingInvoice>(initial);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [collectOpen, setCollectOpen] = useState(false);
  const [discountOpen, setDiscountOpen] = useState(false);
  const [refundFor, setRefundFor] = useState<BillingPayment | null>(null);
  // Held across retries so a resend of the same collection is recognised as a replay, not a second charge.
  const [eventId, setEventId] = useState(newClientEventId);

  async function run(fn: () => Promise<{ invoice: BillingInvoice }>, done?: () => void) {
    setBusy(true);
    setError(null);

    try {
      const result = await fn();
      setInvoice(result.invoice);
      setEventId(newClientEventId());
      done?.();
    } catch (e) {
      setError(isApiError(e) ? t(`billing.errors.${e.code ?? 'unknown'}`, { defaultValue: e.message }) : e instanceof Error ? e.message : String(e));
    } finally {
      setBusy(false);
    }
  }

  const rows: Array<[string, string]> = [
    [t('billing.invoice.subtotal'), formatBdt(invoice.subtotal_paisa, locale)],
    ...(invoice.discount_paisa > 0 ? [[t('billing.invoice.discount'), `− ${formatBdt(invoice.discount_paisa, locale)}`] as [string, string]] : []),
    ...(invoice.coupon_discount_paisa > 0 ? [[t('billing.invoice.coupon'), `− ${formatBdt(invoice.coupon_discount_paisa, locale)}`] as [string, string]] : []),
    ...(invoice.vat_paisa > 0 ? [[t('billing.invoice.vat'), formatBdt(invoice.vat_paisa, locale)] as [string, string]] : []),
    [t('billing.invoice.total'), formatBdt(invoice.total_paisa, locale)],
    [t('billing.invoice.paid'), formatBdt(invoice.paid_paisa, locale)],
    [t('billing.invoice.due'), formatBdt(invoice.due_paisa, locale)],
  ];

  return (
    <Stack spacing={2}>
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} sx={{ alignItems: { sm: 'center' } }}>
        <Stack sx={{ flexGrow: 1 }}>
          <Typography variant="h5" component="h1">{invoice.number}</Typography>
          <Typography variant="body2" color="text.secondary" lang="bn">
            {invoice.patient?.name} · {invoice.patient?.patient_code} {invoice.doctor ? `· ${invoice.doctor.name}` : ''}
          </Typography>
        </Stack>
        <InvoiceStatusChip status={invoice.status} />
        <Button startIcon={<PrintIcon />} href={route('panel.billing.invoices.print', { invoice: invoice.public_id })} target="_blank" rel="noopener">{t('billing.invoice.print')}</Button>
      </Stack>

      {error ? <Alert severity="error" onClose={() => setError(null)}>{error}</Alert> : null}
      {invoice.appointment?.fee_rule_reason ? <Alert severity="info" lang="bn">{invoice.appointment.fee_rule_reason}</Alert> : null}
      {invoice.status === 'void' ? <Alert severity="warning">{t('billing.invoice.voided', { reason: invoice.void_reason ?? '' })}</Alert> : null}

      <Grid container spacing={2}>
        <Grid size={{ xs: 12, md: 7 }}>
          <Card variant="outlined">
            <CardContent>
              <Typography variant="subtitle1" sx={{ mb: 1 }}>{t('billing.invoice.items')}</Typography>
              <Box sx={{ overflowX: 'auto' }}>
                <Table size="small">
                  <TableHead><TableRow><TableCell>{t('billing.invoice.description')}</TableCell><TableCell align="right">{t('billing.invoice.qty')}</TableCell><TableCell align="right">{t('billing.invoice.rate')}</TableCell><TableCell align="right">{t('billing.invoice.amount')}</TableCell></TableRow></TableHead>
                  <TableBody>
                    {(invoice.items ?? []).map((item) => (
                      <TableRow key={item.id}>
                        <TableCell>{item.description}</TableCell>
                        <TableCell align="right">{item.quantity}</TableCell>
                        <TableCell align="right">{formatBdt(item.unit_price_paisa, locale)}</TableCell>
                        <TableCell align="right">{formatBdt(item.line_total_paisa, locale)}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </Box>
            </CardContent>
          </Card>
        </Grid>

        <Grid size={{ xs: 12, md: 5 }}>
          <Card variant="outlined">
            <CardContent>
              <Table size="small">
                <TableBody>
                  {rows.map(([label, value]) => (
                    <TableRow key={label}><TableCell sx={{ border: 0 }}>{label}</TableCell><TableCell align="right" sx={{ border: 0 }}>{value}</TableCell></TableRow>
                  ))}
                </TableBody>
              </Table>
              <Divider sx={{ my: 2 }} />
              <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap' }} useFlexGap>
                {can.collect && invoice.due_paisa > 0 && invoice.status !== 'void' ? <Button variant="contained" onClick={() => setCollectOpen(true)}>{t('billing.invoice.collect')}</Button> : null}
                {can.discount && invoice.due_paisa > 0 && invoice.status !== 'void' ? <Button onClick={() => setDiscountOpen(true)}>{t('billing.invoice.discount_action')}</Button> : null}
              </Stack>
            </CardContent>
          </Card>
        </Grid>
      </Grid>

      <Card variant="outlined">
        <CardContent>
          <Typography variant="subtitle1" sx={{ mb: 1 }}>{t('billing.invoice.payments')}</Typography>
          <Box sx={{ overflowX: 'auto' }}>
            <Table size="small">
              <TableHead><TableRow><TableCell>{t('billing.invoice.receipt')}</TableCell><TableCell>{t('billing.invoice.method')}</TableCell><TableCell>{t('billing.invoice.when')}</TableCell><TableCell align="right">{t('billing.invoice.amount')}</TableCell><TableCell align="right">{t('billing.invoice.refunded')}</TableCell><TableCell /></TableRow></TableHead>
              <TableBody>
                {(invoice.payments ?? []).length === 0 ? (
                  <TableRow><TableCell colSpan={6}><Typography variant="body2" color="text.secondary" sx={{ py: 2, textAlign: 'center' }}>{t('billing.invoice.no_payments')}</Typography></TableCell></TableRow>
                ) : (invoice.payments ?? []).map((payment) => (
                  <TableRow key={payment.public_id}>
                    <TableCell>{payment.receipt_number ?? '—'}</TableCell>
                    <TableCell>{t(`billing.method.${payment.method}`)}</TableCell>
                    <TableCell>{payment.paid_at ? formatDhaka(payment.paid_at, 'D MMM, h:mm a', locale) : '—'}</TableCell>
                    <TableCell align="right">{formatBdt(payment.amount_paisa, locale)}</TableCell>
                    <TableCell align="right">{payment.refunded_paisa > 0 ? formatBdt(payment.refunded_paisa, locale) : '—'}</TableCell>
                    <TableCell align="right">
                      <Stack direction="row" spacing={1} sx={{ justifyContent: 'flex-end' }}>
                        <Button size="small" href={route('panel.billing.invoices.receipt', { invoice: invoice.public_id, payment: payment.public_id })} target="_blank" rel="noopener">{t('billing.invoice.receipt_print')}</Button>
                        {can.refund && payment.refundable_paisa > 0 ? <Button size="small" color="error" onClick={() => setRefundFor(payment)}>{t('billing.invoice.refund_action')}</Button> : null}
                      </Stack>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Box>
        </CardContent>
      </Card>

      {(invoice.refunds ?? []).length > 0 ? (
        <Card variant="outlined">
          <CardContent>
            <Typography variant="subtitle1" sx={{ mb: 1 }}>{t('billing.invoice.refunds')}</Typography>
            <Box sx={{ overflowX: 'auto' }}>
              <Table size="small">
                <TableHead><TableRow><TableCell>{t('billing.invoice.reason')}</TableCell><TableCell>{t('billing.invoice.status')}</TableCell><TableCell align="right">{t('billing.invoice.amount')}</TableCell></TableRow></TableHead>
                <TableBody>
                  {(invoice.refunds ?? []).map((refund) => (
                    <TableRow key={refund.id}>
                      <TableCell>{t(`billing.refund_reason.${refund.reason_code}`)}{refund.reason_note ? ` — ${refund.reason_note}` : ''}</TableCell>
                      <TableCell>{t(`billing.refund_status.${refund.status}`)}</TableCell>
                      <TableCell align="right">{formatBdt(refund.amount_paisa, locale)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </Box>
          </CardContent>
        </Card>
      ) : null}

      <CollectPaymentDialog
        open={collectOpen}
        invoice={invoice}
        busy={busy}
        error={error}
        onClose={() => setCollectOpen(false)}
        onCollect={(amountPaisa, method, note) => void run(
          () => collectPayment(invoice.public_id, { amountPaisa, method, note, clientEventId: eventId }),
          () => setCollectOpen(false),
        )}
      />
      <DiscountDialog
        open={discountOpen}
        busy={busy}
        error={error}
        onClose={() => setDiscountOpen(false)}
        onDiscount={(type, value, reason, note) => void run(() => applyDiscount(invoice.public_id, { type, value, reasonCode: reason, note }), () => setDiscountOpen(false))}
        onCoupon={(code) => void run(() => applyCoupon(invoice.public_id, code), () => setDiscountOpen(false))}
      />
      <RefundDialog
        open={refundFor !== null}
        payment={refundFor}
        busy={busy}
        error={error}
        explanation={null}
        onClose={() => setRefundFor(null)}
        onRefund={(amountPaisa, reason, note) => refundFor && void run(
          () => issueRefund(invoice.public_id, { payment: refundFor.public_id, amountPaisa, reasonCode: reason, note }),
          () => { setRefundFor(null); router.reload({ only: ['invoice'] }); },
        )}
      />
    </Stack>
  );
}

Invoice.layout = (page: ReactNode) => <PanelLayout title="billing.invoice.title">{page}</PanelLayout>;
