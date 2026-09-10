// The console's landing page (Inertia::render('Super/Dashboard')) — the operator's morning screen.
//
// Three questions in this order: what needs a human today (the attention list — every entry is a real count
// with a link to where it is dealt with, and a quiet morning is a short list rather than a wall of zeros), how is
// the platform doing right now (the tiles), and which way is it moving (thirty days of sign-ups and appointments).
import { lazy, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Card from '@mui/material/Card';
import CardActionArea from '@mui/material/CardActionArea';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Divider from '@mui/material/Divider';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import ErrorOutlineIcon from '@mui/icons-material/ErrorOutlined';
import InfoOutlinedIcon from '@mui/icons-material/InfoOutlined';
import LaunchIcon from '@mui/icons-material/Launch';
import WarningAmberIcon from '@mui/icons-material/WarningAmber';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { LazyChart } from '@panel/Components/Charts/LazyChart';
import { SuperNav } from '@panel/Components/Super/SuperNav';
import { SuperTable, type SuperColumn } from '@panel/Components/Super/SuperTable';
import { HealthChip, StatusChip } from '@panel/Components/Super/StatusChip';
import type { AttentionItem, AttentionSeverity, DashboardKpis, PlatformTotals, SuperPlan, TenantRow, TrendDay } from '@panel/Components/Super/types';
import { formatBdt } from '@shared/format/money';
import { formatNumber } from '@shared/format/number';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { hasRoute, route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{
  totals: PlatformTotals;
  kpis: DashboardKpis;
  attention: AttentionItem[];
  trend: TrendDay[];
  recent: TenantRow[];
  plans: SuperPlan[];
}>;

// recharts arrives after first paint (Components/Charts/LazyChart.tsx); the tiles and the list are the screen.
const PlatformTrendChart = lazy(() => import('@panel/Components/Charts/SuperDashboardCharts').then((m) => ({ default: m.PlatformTrendChart })));

/**
 * Literal keys, so `lang:check` sees every label the server can send. An item whose key is not here renders its
 * key, which is the loud failure we want over a silent blank.
 */
const ATTENTION_LABELS: Record<string, string> = {
  overdue_invoices: 'super.dashboard.attention.overdue_invoices',
  failed_jobs: 'super.dashboard.attention.failed_jobs',
  horizon_down: 'super.dashboard.attention.horizon_down',
  queue_backlog: 'super.dashboard.attention.queue_backlog',
  over_limit: 'super.dashboard.attention.over_limit',
  sms_near_limit: 'super.dashboard.attention.sms_near_limit',
  trials_ending: 'super.dashboard.attention.trials_ending',
  backups_never: 'super.dashboard.attention.backups_never',
  backups_stale: 'super.dashboard.attention.backups_stale',
  domains_failed: 'super.dashboard.attention.domains_failed',
  admins_without_2fa: 'super.dashboard.attention.admins_without_2fa',
  suspended_recent: 'super.dashboard.attention.suspended_recent',
  promotions_pending: 'super.dashboard.attention.promotions_pending',
  reconciliation_open: 'super.dashboard.attention.reconciliation_open',
};

const SEVERITY: Record<AttentionSeverity, { color: 'error' | 'warning' | 'info'; icon: ReactNode }> = {
  error: { color: 'error', icon: <ErrorOutlineIcon fontSize="small" /> },
  warning: { color: 'warning', icon: <WarningAmberIcon fontSize="small" /> },
  info: { color: 'info', icon: <InfoOutlinedIcon fontSize="small" /> },
};

function Tile({ label, value, hint, tone = 'text.primary', href, external }: {
  label: string;
  value: string;
  hint?: string;
  tone?: string;
  href?: string;
  external?: boolean;
}) {
  const body = (
    <CardContent sx={{ py: 1.5, '&:last-child': { pb: 1.5 } }}>
      <Typography variant="caption" color="text.secondary" noWrap sx={{ display: 'block' }}>{label}</Typography>
      <Typography variant="h5" sx={{ color: tone, fontVariantNumeric: 'tabular-nums' }}>{value}</Typography>
      {hint ? <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{hint}</Typography> : null}
    </CardContent>
  );

  return (
    <Card variant="outlined" sx={{ height: '100%' }}>
      {href === undefined ? body : external ? (
        <CardActionArea component="a" href={href} target="_blank" rel="noopener" sx={{ height: '100%' }}>{body}</CardActionArea>
      ) : (
        <CardActionArea component={RouterLink} href={href} sx={{ height: '100%' }}>{body}</CardActionArea>
      )}
    </Card>
  );
}

export default function Dashboard({ totals, kpis, attention, trend, recent, plans }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const n = (value: number): string => formatNumber(value, locale);

  const items = attention.filter((item) => item.route === null || hasRoute(item.route));
  const hasTrend = trend.some((d) => d.signups > 0 || d.appointments > 0);
  const queue = kpis.queue;
  const backups = kpis.backups;
  const revenue = kpis.revenue;

  const hrefOf = (item: AttentionItem): string | undefined => {
    if (item.href !== null) return item.href;
    if (item.route !== null && hasRoute(item.route)) return route(item.route, item.params);
    return undefined;
  };

  const backupHint = backups.worst === null
    ? undefined
    : backups.worst.last_backup_at === null
      ? t('super.dashboard.kpi.backup_never_hint', { name: backups.worst.name })
      : t('super.dashboard.kpi.backup_worst_hint', { name: backups.worst.name, at: formatDhaka(backups.worst.last_backup_at, 'D MMM, h:mm a', locale) });

  const statusTiles: Array<{ key: string; label: string; value: number; status: string }> = [
    { key: 'trial', label: 'super.status.trial', value: totals.trial, status: 'trial' },
    { key: 'active', label: 'super.status.active', value: totals.active, status: 'active' },
    { key: 'past_due', label: 'super.status.past_due', value: totals.past_due, status: 'past_due' },
    { key: 'suspended', label: 'super.status.suspended', value: totals.suspended, status: 'suspended' },
    { key: 'cancelled', label: 'super.status.cancelled', value: totals.cancelled, status: 'cancelled' },
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

  const tenantsHref = (status: string): string | undefined => (hasRoute('super.tenants.index') ? route('super.tenants.index', { status }) : undefined);
  const usageHref = (params: Record<string, string>): string | undefined => (hasRoute('super.usage.index') ? route('super.usage.index', params) : undefined);

  return (
    <Box>
      <SuperNav />

      <Stack spacing={2}>
        <Box>
          <Stack direction="row" spacing={1} sx={{ alignItems: 'center', mb: 1 }}>
            <Typography variant="subtitle1" component="h2">{t('super.dashboard.attention_title')}</Typography>
            {items.length === 0 ? <Chip size="small" color="success" variant="outlined" label={t('super.dashboard.attention_calm')} /> : (
              <Chip size="small" color="warning" variant="outlined" label={t('super.dashboard.attention_count', { count: n(items.length) })} />
            )}
          </Stack>
          {items.length === 0 ? (
            <Card variant="outlined"><CardContent sx={{ py: 1.5, '&:last-child': { pb: 1.5 } }}>
              <Typography variant="body2" color="text.secondary">{t('super.dashboard.attention_empty')}</Typography>
            </CardContent></Card>
          ) : (
            <Card variant="outlined">
              <Stack divider={<Divider flexItem />} data-testid="attention-list">
                {items.map((item) => {
                  const severity = SEVERITY[item.severity];
                  const href = hrefOf(item);
                  const label = t(ATTENTION_LABELS[item.key] ?? item.key, { count: n(item.count) });
                  const body = (
                    <Stack direction="row" spacing={1.5} sx={{ alignItems: 'center', px: 2, py: 1.25 }}>
                      <Box sx={{ color: `${severity.color}.main`, display: 'flex' }}>{severity.icon}</Box>
                      <Typography variant="h6" sx={{ color: `${severity.color}.main`, fontVariantNumeric: 'tabular-nums', minWidth: 40 }}>{n(item.count)}</Typography>
                      <Box sx={{ flexGrow: 1, minWidth: 0 }}>
                        <Typography variant="body2">{label}</Typography>
                        {item.amount_paisa !== undefined ? (
                          <Typography variant="caption" color="text.secondary">{t('super.dashboard.attention_amount', { amount: formatBdt(item.amount_paisa, locale) })}</Typography>
                        ) : null}
                      </Box>
                      {item.href !== null ? <LaunchIcon fontSize="small" sx={{ color: 'text.disabled' }} /> : null}
                    </Stack>
                  );

                  if (href === undefined) return <Box key={item.key}>{body}</Box>;

                  return item.href !== null ? (
                    <CardActionArea key={item.key} component="a" href={href} target="_blank" rel="noopener">{body}</CardActionArea>
                  ) : (
                    <CardActionArea key={item.key} component={RouterLink} href={href}>{body}</CardActionArea>
                  );
                })}
              </Stack>
            </Card>
          )}
        </Box>

        <Divider />

        <Box>
          <Typography variant="subtitle1" component="h2" sx={{ mb: 1 }}>{t('super.dashboard.kpi_title')}</Typography>
          <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: 'repeat(2, 1fr)', sm: 'repeat(3, 1fr)', lg: 'repeat(6, 1fr)' } }} data-testid="kpi-tiles">
            <Tile label={t('super.dashboard.tenants')} value={n(totals.tenants)} href={hasRoute('super.tenants.index') ? route('super.tenants.index') : undefined} />
            {statusTiles.map((tile) => <Tile key={tile.key} label={t(tile.label)} value={n(tile.value)} href={tenantsHref(tile.status)} />)}
          </Box>
          <Box sx={{ display: 'grid', gap: 1.5, mt: 1.5, gridTemplateColumns: { xs: 'repeat(2, 1fr)', sm: 'repeat(3, 1fr)', lg: 'repeat(5, 1fr)' } }}>
            <Tile
              label={t('super.dashboard.kpi.trials_ending')} value={n(kpis.trials_ending_7d)}
              tone={kpis.trials_ending_7d > 0 ? 'warning.main' : 'text.primary'} href={tenantsHref('trial')}
            />
            <Tile
              label={t('super.dashboard.kpi.past_due')} value={n(kpis.past_due.tenants)}
              hint={t('super.dashboard.kpi.past_due_hint', { amount: formatBdt(kpis.past_due.paisa, locale), invoices: n(kpis.past_due.invoices) })}
              tone={kpis.past_due.tenants > 0 ? 'error.main' : 'text.primary'} href={tenantsHref('past_due')}
            />
            <Tile label={t('super.dashboard.kpi.signups_month')} value={n(kpis.signups_month)} />
            <Tile label={t('super.dashboard.kpi.appointments_today')} value={n(kpis.appointments_today)} hint={t('super.dashboard.kpi.appointments_today_hint')} />
            <Tile
              label={t('super.dashboard.kpi.sms_near_limit')} value={n(kpis.sms_near_limit)}
              tone={kpis.sms_near_limit > 0 ? 'warning.main' : 'text.primary'} href={usageHref({ metric: 'sms_credits', filter: 'near' })}
            />
          </Box>
          <Box sx={{ display: 'grid', gap: 1.5, mt: 1.5, gridTemplateColumns: { xs: 'repeat(2, 1fr)', sm: 'repeat(3, 1fr)', lg: 'repeat(6, 1fr)' } }}>
            <Tile
              label={t('super.dashboard.kpi.failed_jobs')} value={n(queue.failed)}
              hint={queue.failed_recent > 0 ? t('super.dashboard.kpi.failed_recent_hint', { count: n(queue.failed_recent) }) : undefined}
              tone={queue.failed > 0 ? 'error.main' : 'text.primary'} href={`${queue.horizon_url}/failed`} external
            />
            <Tile
              label={t('super.dashboard.kpi.queue_depth')}
              value={queue.available ? n(queue.depth) : '—'}
              hint={!queue.available ? t('super.dashboard.kpi.queue_unavailable') : !queue.running ? t('super.dashboard.kpi.horizon_stopped') : queue.longest_wait > 0 ? t('super.dashboard.kpi.queue_wait_hint', { seconds: n(queue.longest_wait) }) : t('super.dashboard.kpi.horizon_running')}
              tone={!queue.available || !queue.running ? 'error.main' : queue.longest_wait >= 60 ? 'warning.main' : 'text.primary'}
              href={queue.horizon_url} external
            />
            <Tile
              label={t('super.dashboard.kpi.backup_oldest')}
              value={backups.oldest_hours === null ? (backups.never > 0 ? t('super.dashboard.kpi.backup_never') : '—') : t('super.dashboard.kpi.hours', { count: n(backups.oldest_hours) })}
              hint={backupHint}
              tone={backups.never > 0 || backups.stale > 0 ? 'warning.main' : 'text.primary'}
              href={backups.worst !== null && hasRoute('super.tenants.show') ? route('super.tenants.show', { tenant: backups.worst.public_id }) : undefined}
            />
            <Tile label={t('super.dashboard.mrr')} value={formatBdt(revenue.mrr_paisa, locale)} hint={t('super.dashboard.mrr_note')} />
            <Tile
              label={t('super.dashboard.outstanding')} value={formatBdt(revenue.outstanding_paisa, locale)}
              hint={t('super.dashboard.kpi.outstanding_hint', { invoices: n(revenue.outstanding_invoices) })}
              tone={revenue.outstanding_paisa > 0 ? 'error.main' : 'text.primary'}
            />
            <Tile
              label={t('super.dashboard.kpi.collected_month')} value={formatBdt(revenue.collected_month_paisa, locale)}
              hint={t('super.dashboard.kpi.collected_month_hint', { payments: n(revenue.collected_month_payments) })}
            />
          </Box>
        </Box>

        <Card variant="outlined">
          <CardContent>
            <Stack direction="row" spacing={1} sx={{ alignItems: 'baseline', mb: 1, flexWrap: 'wrap' }}>
              <Typography variant="subtitle1" component="h2">{t('super.dashboard.trend.title')}</Typography>
              <Typography variant="caption" color="text.secondary">
                {t('super.dashboard.trend.summary', {
                  signups: n(trend.reduce((sum, d) => sum + d.signups, 0)),
                  appointments: n(trend.reduce((sum, d) => sum + d.appointments, 0)),
                })}
              </Typography>
            </Stack>
            <LazyChart height={220} empty={!hasTrend} emptyLabel={t('super.dashboard.trend.empty')}>
              <PlatformTrendChart days={trend} />
            </LazyChart>
          </CardContent>
        </Card>

        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', lg: '2fr 1fr' } }}>
          <Card variant="outlined">
            <CardContent sx={{ pb: 0 }}>
              <Typography variant="subtitle1" component="h2">{t('super.dashboard.recent_title')}</Typography>
            </CardContent>
            <SuperTable columns={columns} rows={recent} rowKey={(row) => row.public_id} empty={t('super.tenants.empty')} label={t('super.dashboard.recent_title')} />
          </Card>

          <Stack spacing={2}>
            <Card variant="outlined">
              <CardContent>
                <Typography variant="subtitle1" component="h2" sx={{ mb: 1 }}>{t('super.dashboard.queue.title')}</Typography>
                {!queue.available ? (
                  <Typography variant="body2" color="text.secondary">{t('super.dashboard.kpi.queue_unavailable')}</Typography>
                ) : queue.queues.length === 0 ? (
                  <Typography variant="body2" color="text.secondary">{t('super.dashboard.queue.empty')}</Typography>
                ) : (
                  <Stack spacing={0.5}>
                    {queue.queues.map((q) => (
                      <Stack key={q.name} direction="row" spacing={1} sx={{ alignItems: 'baseline', justifyContent: 'space-between' }}>
                        <Typography variant="body2" sx={{ fontFamily: 'monospace' }}>{q.name}</Typography>
                        <Typography variant="body2" color={q.wait >= 60 ? 'warning.main' : 'text.secondary'} sx={{ fontVariantNumeric: 'tabular-nums' }}>
                          {t('super.dashboard.queue.row', { length: n(q.length), wait: n(q.wait) })}
                        </Typography>
                      </Stack>
                    ))}
                  </Stack>
                )}
              </CardContent>
            </Card>

            {plans.length > 0 ? (
              <Card variant="outlined">
                <CardContent>
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
                </CardContent>
              </Card>
            ) : null}
          </Stack>
        </Box>
      </Stack>
    </Box>
  );
}

Dashboard.layout = (page: ReactNode) => <PanelLayout title="super.dashboard.title">{page}</PanelLayout>;
