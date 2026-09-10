// The billing desk's overview: what the platform earns (MRR / ARR), what it is owed (overdue, outstanding), what it
// collected this month, how the subscription book is split by status — then the six-month collected-vs-invoiced
// table, the clinics to chase, and what the next dunning run will do. Every number is integer paisa from
// PlatformRevenueSummary; nothing is computed on the client.
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { SuperNav } from '@panel/Components/Super/SuperNav';
import { SuperTable, type SuperColumn } from '@panel/Components/Super/SuperTable';
import { StatusChip } from '@panel/Components/Super/StatusChip';
import { BillingNav } from '@panel/Components/Super/Billing/BillingNav';
import type { ArrearsRow, CollectedMonth, DunningTotals, RevenueSummary } from '@panel/Components/Super/Billing/types';
import { formatBdt } from '@shared/format/money';
import { formatNumber } from '@shared/format/number';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { hasRoute, route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{
  summary: RevenueSummary;
  months: CollectedMonth[];
  arrears: ArrearsRow[];
  dunning: DunningTotals;
}>;

function Tile({ label, value, hint, tone = 'text.primary', href }: { label: string; value: string; hint?: string; tone?: string; href?: string }) {
  const body = (
    <CardContent sx={{ py: 1.5, '&:last-child': { pb: 1.5 } }}>
      <Typography variant="caption" color="text.secondary" noWrap sx={{ display: 'block' }}>{label}</Typography>
      <Typography variant="h5" sx={{ color: tone, fontVariantNumeric: 'tabular-nums' }}>{value}</Typography>
      {hint ? <Typography variant="caption" color="text.secondary">{hint}</Typography> : null}
    </CardContent>
  );

  return (
    <Card variant="outlined" sx={{ height: '100%', textDecoration: 'none' }} component={href ? RouterLink : 'div'} href={href}>
      {body}
    </Card>
  );
}

export default function Index({ summary, months, arrears, dunning }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const n = (value: number): string => formatNumber(value, locale);
  const money = (paisa: number): string => formatBdt(paisa, locale);
  const subs = hasRoute('super.billing.subscriptions.index');
  const invs = hasRoute('super.billing.invoices.index');

  const monthColumns: SuperColumn<CollectedMonth>[] = [
    { key: 'period', label: t('super.billing.overview.month'), render: (row) => formatDhaka(`${row.period}-01`, 'MMM YYYY', locale) },
    { key: 'invoiced', label: t('super.billing.overview.invoiced'), align: 'right', render: (row) => money(row.invoiced_paisa) },
    { key: 'invoices', label: t('super.billing.nav.invoices'), align: 'right', render: (row) => n(row.invoices) },
    { key: 'collected', label: t('super.billing.overview.collected'), align: 'right', render: (row) => <Typography variant="body2" sx={{ fontWeight: 600, fontVariantNumeric: 'tabular-nums' }}>{money(row.collected_paisa)}</Typography> },
    { key: 'payments', label: t('super.billing.nav.payments'), align: 'right', render: (row) => n(row.payments) },
  ];

  const arrearsColumns: SuperColumn<ArrearsRow>[] = [
    {
      key: 'clinic',
      label: t('super.tenants.column.clinic'),
      bn: true,
      render: (row) => (
        <Box>
          {hasRoute('super.tenants.show') ? (
            <Box component={RouterLink} href={route('super.tenants.show', { tenant: row.public_id })} sx={{ color: 'primary.main', fontWeight: 600, textDecoration: 'none' }}>{row.name}</Box>
          ) : <Typography variant="body2" sx={{ fontWeight: 600 }}>{row.name}</Typography>}
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{row.slug}</Typography>
        </Box>
      ),
    },
    { key: 'status', label: t('super.tenants.column.status'), render: (row) => <StatusChip status={row.status} /> },
    { key: 'invoices', label: t('super.billing.nav.invoices'), align: 'right', render: (row) => n(row.invoices) },
    { key: 'oldest', label: t('super.billing.overview.oldest_due'), render: (row) => (row.oldest_due_at === null ? '—' : formatDhaka(row.oldest_due_at, 'D MMM YYYY', locale)) },
    { key: 'arrears', label: t('super.tenants.column.arrears'), align: 'right', render: (row) => <Typography variant="body2" sx={{ color: 'error.main', fontWeight: 600, fontVariantNumeric: 'tabular-nums' }}>{money(row.arrears_paisa)}</Typography> },
    ...(invs ? [{
      key: 'open',
      label: t('super.billing.column.actions'),
      align: 'right' as const,
      render: (row: ArrearsRow) => <Button size="small" component={RouterLink} href={route('super.billing.invoices.index', { tenant: row.public_id, status: 'past_due' })}>{t('super.billing.open_ledger')}</Button>,
    }] : []),
  ];

  return (
    <Box>
      <SuperNav />
      <BillingNav />

      <Stack spacing={2}>
        <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: '1fr', sm: 'repeat(2, 1fr)', lg: 'repeat(4, 1fr)' } }}>
          <Tile label={t('super.billing.overview.mrr')} value={money(summary.mrr_paisa)} hint={t('super.billing.overview.mrr_hint', { count: n(summary.recurring_subscriptions) })} />
          <Tile label={t('super.billing.overview.arr')} value={money(summary.arr_paisa)} hint={t('super.billing.overview.arr_hint')} />
          <Tile label={t('super.billing.overview.collected_month')} value={money(summary.collected_month_paisa)} hint={t('super.billing.overview.collected_hint', { count: n(summary.collected_month_payments) })} tone="success.main" />
          <Tile
            label={t('super.billing.overview.overdue')}
            value={money(summary.overdue_paisa)}
            hint={t('super.billing.overview.overdue_hint', { count: n(summary.overdue_invoices), outstanding: money(summary.outstanding_paisa) })}
            tone={summary.overdue_paisa > 0 ? 'error.main' : 'text.primary'}
            href={invs ? route('super.billing.invoices.index', { status: 'past_due' }) : undefined}
          />
        </Box>

        <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: 'repeat(2, 1fr)', sm: 'repeat(3, 1fr)', lg: 'repeat(6, 1fr)' } }}>
          <Tile label={t('super.status.trialing')} value={n(summary.trialing)} href={subs ? route('super.billing.subscriptions.index', { status: 'trialing' }) : undefined} />
          <Tile label={t('super.status.active')} value={n(summary.active)} tone="success.main" href={subs ? route('super.billing.subscriptions.index', { status: 'active' }) : undefined} />
          <Tile label={t('super.status.past_due')} value={n(summary.past_due)} tone={summary.past_due > 0 ? 'warning.main' : 'text.primary'} href={subs ? route('super.billing.subscriptions.index', { status: 'past_due' }) : undefined} />
          <Tile label={t('super.status.suspended')} value={n(summary.suspended)} tone={summary.suspended > 0 ? 'error.main' : 'text.primary'} href={subs ? route('super.billing.subscriptions.index', { status: 'suspended' }) : undefined} />
          <Tile label={t('super.status.cancelled')} value={n(summary.cancelled)} href={subs ? route('super.billing.subscriptions.index', { status: 'cancelled' }) : undefined} />
          <Tile label={t('super.status.expired')} value={n(summary.expired)} href={subs ? route('super.billing.subscriptions.index', { status: 'expired' }) : undefined} />
        </Box>

        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', lg: '3fr 2fr' } }}>
          <Card variant="outlined">
            <CardContent sx={{ pb: 0 }}>
              <Typography variant="subtitle1" component="h2">{t('super.billing.overview.months_title')}</Typography>
              <Typography variant="caption" color="text.secondary">{t('super.billing.overview.months_hint')}</Typography>
            </CardContent>
            <SuperTable columns={monthColumns} rows={months} rowKey={(row) => row.period} empty={t('super.billing.overview.months_empty')} label={t('super.billing.overview.months_title')} />
          </Card>

          <Card variant="outlined" sx={{ borderColor: dunning.suspensions > 0 ? 'error.main' : dunning.notices > 0 ? 'warning.main' : 'divider' }}>
            <CardContent>
              <Typography variant="subtitle1" component="h2">{t('super.billing.nav.dunning')}</Typography>
              <Typography variant="body2" sx={{ mt: 1 }}>
                {dunning.tenants === 0
                  ? t('super.billing.dunning.calm')
                  : t('super.billing.dunning.summary', { tenants: n(dunning.tenants), notices: n(dunning.notices), suspensions: n(dunning.suspensions), amount: money(dunning.arrears_paisa) })}
              </Typography>
              <Stack direction="row" spacing={1} sx={{ mt: 2, flexWrap: 'wrap' }} useFlexGap>
                {hasRoute('super.billing.dunning.index') ? (
                  <Button size="small" variant="outlined" component={RouterLink} href={route('super.billing.dunning.index')}>{t('super.billing.dunning.open')}</Button>
                ) : null}
                {summary.draft_invoices > 0 && invs ? (
                  <Button size="small" component={RouterLink} href={route('super.billing.invoices.index', { status: 'draft' })}>{t('super.billing.overview.drafts', { count: n(summary.draft_invoices) })}</Button>
                ) : null}
              </Stack>
            </CardContent>
          </Card>
        </Box>

        <Card variant="outlined">
          <CardContent sx={{ pb: 0 }}>
            <Typography variant="subtitle1" component="h2">{t('super.billing.overview.arrears_title')}</Typography>
          </CardContent>
          <SuperTable columns={arrearsColumns} rows={arrears} rowKey={(row) => row.public_id} empty={t('super.billing.overview.arrears_empty')} label={t('super.billing.overview.arrears_title')} />
        </Card>
      </Stack>
    </Box>
  );
}

Index.layout = (page: ReactNode) => <PanelLayout title="super.billing.page_title">{page}</PanelLayout>;
