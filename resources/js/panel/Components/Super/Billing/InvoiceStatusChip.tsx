// An invoice's state, as colour AND text. `past_due` is not a stored status — it is "issued/overdue and late",
// the accountant's reading — so it is a second, smaller chip rather than a colour swap.
import Chip from '@mui/material/Chip';
import Stack from '@mui/material/Stack';
import { useTranslation } from 'react-i18next';
import type { ChipProps } from '@mui/material/Chip';
import type { InvoiceStatusValue } from '../types';
import type { PaymentStatusValue } from './types';

const INVOICE: Record<InvoiceStatusValue, { tone: ChipProps['color']; label: string }> = {
  draft: { tone: 'default', label: 'saas.invoice.status.draft' },
  issued: { tone: 'info', label: 'saas.invoice.status.issued' },
  paid: { tone: 'success', label: 'saas.invoice.status.paid' },
  overdue: { tone: 'error', label: 'saas.invoice.status.overdue' },
  void: { tone: 'default', label: 'saas.invoice.status.void' },
};

const PAYMENT: Record<PaymentStatusValue, { tone: ChipProps['color']; label: string }> = {
  pending: { tone: 'warning', label: 'super.billing.payment_status.pending' },
  succeeded: { tone: 'success', label: 'super.billing.payment_status.succeeded' },
  failed: { tone: 'error', label: 'super.billing.payment_status.failed' },
  refunded: { tone: 'default', label: 'super.billing.payment_status.refunded' },
};

export function InvoiceStatusChip({ status, pastDue = false, size = 'small' }: { status: InvoiceStatusValue; pastDue?: boolean; size?: ChipProps['size'] }) {
  const { t } = useTranslation();
  const entry = INVOICE[status];

  return (
    <Stack direction="row" spacing={0.5} sx={{ alignItems: 'center' }}>
      <Chip size={size} color={entry.tone} variant={status === 'void' ? 'outlined' : 'filled'} label={t(entry.label)} />
      {pastDue && status === 'issued' ? <Chip size="small" color="warning" variant="outlined" label={t('super.billing.past_due')} /> : null}
    </Stack>
  );
}

export function PaymentStatusChip({ status, size = 'small' }: { status: PaymentStatusValue; size?: ChipProps['size'] }) {
  const { t } = useTranslation();
  const entry = PAYMENT[status];

  return <Chip size={size} color={entry.tone} variant={status === 'succeeded' ? 'filled' : 'outlined'} label={t(entry.label)} />;
}

/** The six payment methods, in the console's vocabulary; the three gateways keep their customer-facing names. */
export const PAYMENT_METHOD_LABELS: Record<string, string> = {
  bkash: 'saas.gateway.bkash',
  nagad: 'saas.gateway.nagad',
  sslcommerz: 'saas.gateway.sslcommerz',
  bank_transfer: 'super.billing.method.bank_transfer',
  cash: 'super.billing.method.cash',
  manual: 'super.billing.method.manual',
};

/** The manual methods an operator can record by hand — a gateway payment only ever arrives through its callback. */
export const MANUAL_METHODS: string[] = ['bank_transfer', 'bkash', 'nagad', 'cash', 'manual'];
