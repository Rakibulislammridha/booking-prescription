// The console's landing page (Inertia::render('Super/Dashboard')).
//
// It answers two questions in that order: what needs a human today, and what shape is the platform in. The
// attention row is first because it is the only part that ever asks for work; when every count is zero it reads
// as calm rather than as four alarming zeros.
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Card from '@mui/material/Card';
import CardActionArea from '@mui/material/CardActionArea';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Divider from '@mui/material/Divider';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import ScienceIcon from '@mui/icons-material/Science';
import RuleIcon from '@mui/icons-material/Rule';
import ReceiptLongIcon from '@mui/icons-material/ReceiptLong';
import HourglassBottomIcon from '@mui/icons-material/HourglassBottom';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { SuperNav } from '@panel/Components/Super/SuperNav';
import { SuperTable, type SuperColumn } from '@panel/Components/Super/SuperTable';
import { HealthChip, StatusChip } from '@panel/Components/Super/StatusChip';
import type { PlatformTotals, SuperPlan, TenantRow } from '@panel/Components/Super/types';
import { formatBdt } from '@shared/format/money';
import { formatNumber } from '@shared/format/number';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { hasRoute, route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

interface Attention {
  pending_promotions: number;
  unresolved_reconciliation: number;
  overdue_invoices: number;
  trials_ending_7d: number;
}

type Props = PageProps<{
  totals: PlatformTotals;
  attention: Attention;
  recent: TenantRow[];
  plans: SuperPlan[];
}>;

interface AttentionCard {
  key: string;
  label: string;
  count: number;
  icon: ReactNode;
  routeName: string;
  params?: Record<string, string>;
}

function Tile({ label, value, hint, tone = 'text.primary' }: { label: string; value: string; hint?: string; tone?: string }) {
  return (
    <Card variant="outlined" sx={{ height: '100%' }}>
      <CardContent sx={{ py: 1.5, '&:last-child': { pb: 1.5 } }}>
        <Typography variant="caption" color="text.secondary" noWrap>{label}</Typography>
        <Typography variant="h5" sx={{ color: tone, fontVariantNumeric: 'tabular-nums' }}>{value}</Typography>
        {hint ? <Typography variant="caption" color="text.secondary">{hint}</Typography> : null}
      </CardContent>
    </Card>
  );
}

export default function Dashboard({ totals, attention, recent, plans }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const n = (value: number): string => formatNumber(value, locale);

  const cards: AttentionCard[] = [
    { key: 'promotions', label: 'super.dashboard.attention.promotions', count: attention.pending_promotions, icon: <ScienceIcon />, routeName: 'super.catalog.review' },
    { key: 'reconciliation', label: 'super.dashboard.attention.reconciliation', count: attention.unresolved_reconciliation, icon: <RuleIcon />, routeName: 'super.catalog.reconciliation.index' },
    { key: 'overdue', label: 'super.dashboard.attention.overdue', count: attention.overdue_invoices, icon: <ReceiptLongIcon />, routeName: 'super.tenants.index', params: { status: 'past_due' } },
    { key: 'trials', label: 'super.dashboard.attention.trials', count: attention.trials_ending_7d, icon: <HourglassBottomIcon />, routeName: 'super.tenants.index', params: { status: 'trial' } },
  ].filter((card) => hasRoute(card.routeName));

  const calm = cards.every((card) => card.count === 0);

  const statusTiles: Array<{ key: string; label: string; value: number }> = [
    { key: 'trial', label: 'super.status.trial', value: totals.trial },
    { key: 'active', label: 'super.status.active', value: totals.active },
    { key: 'past_due', label: 'super.status.past_due', value: totals.past_due },
    { key: 'suspended', label: 'super.status.suspended', value: totals.suspended },
    { key: 'cancelled', label: 'super.status.cancelled', value: totals.cancelled },
  ];

  const columns: SuperColumn<TenantRow>[] = [
    {
      key: 'clinic',
      label: t('super.tenants.column.clinic'),
      bn: true,
      render: (row) => (
        <Stack>
          {hasRoute('super.tenants.show') ? (
            <Box component={RouterLink} href={route('super.tenants.show', { tenant: row.public_id })} sx={{ color: 'primary.main', fontWeight: 600, textDecoration: 'none' }}>
              {row.name}
            </Box>
          ) : <Typography variant="body2" sx={{ fontWeight: 600 }}>{row.name}</Typography>}
          <Typography variant="caption" color="text.secondary">{row.slug}</Typography>
        </Stack>
      ),
    },
    { key: 'status', label: t('super.tenants.column.status'), render: (row) => <StatusChip status={row.status} /> },
    { key: 'plan', label: t('super.tenants.column.plan'), render: (row) => row.plan_name },
    { key: 'health', label: t('super.tenants.column.health'), render: (row) => <HealthChip health={row.health} /> },
    {
      key: 'arrears',
      label: t('super.tenants.column.arrears'),
      align: 'right',
      render: (row) => (
        <Typography variant="body2" sx={{ color: row.arrears_paisa > 0 ? 'error.main' : 'text.secondary', fontVariantNumeric: 'tabular-nums' }}>
          {formatBdt(row.arrears_paisa, locale)}
        </Typography>
      ),
    },
    { key: 'created', label: t('super.tenants.column.created'), render: (row) => (row.created_at ? formatDhaka(row.created_at, 'D MMM YYYY', locale) : '—') },
  ];

  return (
    <Box>
      <SuperNav />

      <Stack spacing={2}>
        <Box>
          <Stack direction="row" spacing={1} sx={{ alignItems: 'center', mb: 1 }}>
            <Typography variant="subtitle1" component="h2">{t('super.dashboard.attention_title')}</Typography>
            {calm ? <Chip size="small" color="success" variant="outlined" label={t('super.dashboard.attention_calm')} /> : null}
          </Stack>
          <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: '1fr', sm: 'repeat(2, 1fr)', lg: 'repeat(4, 1fr)' } }}>
            {cards.map((card) => (
              <Card key={card.key} variant="outlined" sx={{ borderColor: card.count > 0 ? 'warning.main' : 'divider' }}>
                <CardActionArea component={RouterLink} href={route(card.routeName, card.params)} sx={{ height: '100%' }}>
                  <CardContent sx={{ py: 1.5, '&:last-child': { pb: 1.5 } }}>
                    <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
                      <Box sx={{ color: card.count > 0 ? 'warning.main' : 'text.disabled', display: 'flex' }}>{card.icon}</Box>
                      <Box sx={{ minWidth: 0 }}>
                        <Typography variant="h5" sx={{ color: card.count > 0 ? 'warning.main' : 'text.secondary', fontVariantNumeric: 'tabular-nums' }}>
                          {n(card.count)}
                        </Typography>
                        <Typography variant="caption" color="text.secondary">{t(card.label)}</Typography>
                      </Box>
                    </Stack>
                  </CardContent>
                </CardActionArea>
              </Card>
            ))}
          </Box>
        </Box>

        <Divider />

        <Box>
          <Typography variant="subtitle1" component="h2" sx={{ mb: 1 }}>{t('super.dashboard.platform_title')}</Typography>
          <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: 'repeat(2, 1fr)', sm: 'repeat(3, 1fr)', lg: 'repeat(6, 1fr)' } }}>
            <Tile label={t('super.dashboard.tenants')} value={n(totals.tenants)} />
            {statusTiles.map((tile) => <Tile key={tile.key} label={t(tile.label)} value={n(tile.value)} />)}
          </Box>
          <Box sx={{ display: 'grid', gap: 1.5, mt: 1.5, gridTemplateColumns: { xs: '1fr', sm: 'repeat(2, 1fr)', lg: 'repeat(4, 1fr)' } }}>
            <Tile label={t('super.dashboard.mrr')} value={formatBdt(totals.mrr_paisa, locale)} hint={t('super.dashboard.mrr_note')} />
            <Tile
              label={t('super.dashboard.outstanding')}
              value={formatBdt(totals.outstanding_paisa, locale)}
              tone={totals.outstanding_paisa > 0 ? 'error.main' : 'text.primary'}
            />
            <Tile label={t('super.dashboard.appointments_month')} value={n(totals.appointments_this_month)} />
            <Tile label={t('super.dashboard.sms_month')} value={n(totals.sms_this_month)} />
          </Box>
        </Box>

        {plans.length > 0 ? (
          <Box>
            <Typography variant="subtitle1" component="h2" sx={{ mb: 1 }}>{t('super.dashboard.plans_title')}</Typography>
            <Stack direction="row" spacing={1} useFlexGap sx={{ flexWrap: 'wrap' }}>
              {plans.map((plan) => (
                <Chip
                  key={plan.code}
                  size="small"
                  variant="outlined"
                  color={plan.is_featured ? 'primary' : 'default'}
                  label={`${plan.name} · ${formatBdt(plan.price_monthly_paisa, locale)}`}
                />
              ))}
            </Stack>
          </Box>
        ) : null}

        <Card variant="outlined">
          <CardContent sx={{ pb: 0 }}>
            <Typography variant="subtitle1" component="h2">{t('super.dashboard.recent_title')}</Typography>
          </CardContent>
          <SuperTable columns={columns} rows={recent} rowKey={(row) => row.public_id} empty={t('super.tenants.empty')} label={t('super.dashboard.recent_title')} />
        </Card>
      </Stack>
    </Box>
  );
}

Dashboard.layout = (page: ReactNode) => <PanelLayout title="super.dashboard.title">{page}</PanelLayout>;
