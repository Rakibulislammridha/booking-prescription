// One metric across the whole platform for twelve months, then every clinic's counter against its cap. The
// point of the screen is capacity planning and pricing: "we sell 5,000 SMS a month and the platform sends
// 41,000" — and, per clinic, who is about to hit a wall. A row opens the clinic's own drill-down.
import { lazy, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import LinearProgress from '@mui/material/LinearProgress';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import Typography from '@mui/material/Typography';
import ChevronLeftIcon from '@mui/icons-material/ChevronLeft';
import ChevronRightIcon from '@mui/icons-material/ChevronRight';
import DownloadIcon from '@mui/icons-material/Download';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { LazyChart } from '@panel/Components/Charts/LazyChart';
import { SuperTable, type SuperColumn } from '@panel/Components/Super/SuperTable';
import { StatusChip } from '@panel/Components/Super/StatusChip';
import { bytesParts } from '@panel/Components/Super/UsageBars';
import type { ConsoleMeta, PlatformTotals, UsageBoardFilter, UsageBoardRow, UsageBoardSort, UsageSeriesPoint } from '@panel/Components/Super/types';
import { formatBdt } from '@shared/format/money';
import { formatNumber } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{
  metric: string;
  metrics: string[];
  metric_labels: Record<string, string>;
  is_bytes: boolean;
  is_capped: boolean;
  series: UsageSeriesPoint[];
  board: UsageBoardRow[];
  meta: ConsoleMeta;
  counts: { all: number; over: number; near: number };
  filters: { filter: UsageBoardFilter; sort: UsageBoardSort };
  near_percent: number;
  totals: PlatformTotals;
}>;

// recharts is ~97 KB gzip; the tiles and the board are what the screen is for. The chart arrives after first
// paint and not at all for a metric nobody has recorded (Components/Charts/LazyChart.tsx).
const UsageSeriesChart = lazy(() => import('@panel/Components/Charts/SuperCharts').then((m) => ({ default: m.UsageSeriesChart })));

export default function Index({ metric, metrics, metric_labels, is_bytes, is_capped, series, board, meta, counts, filters, near_percent, totals }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const label = metric_labels[metric] ?? metric;
  const n = (value: number): string => formatNumber(value, locale);

  const amount = (value: number): string => {
    if (!is_bytes) return n(value);
    const parts = bytesParts(value, locale);
    return t(parts.key, { size: parts.size });
  };

  const go = (next: { metric?: string; filter?: UsageBoardFilter; sort?: UsageBoardSort; page?: number }): void => {
    const q: Record<string, string | number> = { metric: next.metric ?? metric };
    const filter = next.filter ?? filters.filter;
    const sort = next.sort ?? filters.sort;
    if (filter !== 'all') q.filter = filter;
    if (sort !== 'percent') q.sort = sort;
    if (next.page !== undefined && next.page > 1) q.page = next.page;
    router.get(route('super.usage.index'), q, { preserveState: true, replace: true, preserveScroll: true });
  };

  const exportHref = route('super.usage.export', filters.filter === 'all' ? { metric } : { metric, filter: filters.filter });

  const columns: SuperColumn<UsageBoardRow>[] = [
    {
      key: 'clinic',
      label: t('super.usage.column.clinic'),
      bn: true,
      render: (row) => (
        <Box>
          <Typography variant="body2" sx={{ fontWeight: 600 }}>{row.name}</Typography>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{row.slug} · {row.plan_name}</Typography>
        </Box>
      ),
    },
    { key: 'status', label: t('super.usage.column.status'), render: (row) => <StatusChip status={row.status} /> },
    { key: 'value', label: label, align: 'right', render: (row) => amount(row.value) },
    {
      key: 'limit',
      label: t('super.usage.column.limit'),
      align: 'right',
      render: (row) => (row.limit === null ? t('saas.pricing.unlimited') : amount(row.limit)),
    },
    {
      key: 'share',
      label: t('super.usage.column.share'),
      align: 'right',
      render: (row) => (row.percent === null ? '—' : (
        <Stack direction="row" spacing={1} sx={{ alignItems: 'center', justifyContent: 'flex-end' }}>
          <Box sx={{ width: 80 }}>
            <LinearProgress
              variant="determinate"
              value={Math.min(100, row.percent)}
              color={row.exhausted ? 'error' : row.near ? 'warning' : 'primary'}
              aria-label={label}
              sx={{ height: 6, borderRadius: 1 }}
            />
          </Box>
          <Typography variant="body2" sx={{ minWidth: 44, textAlign: 'right', color: row.exhausted ? 'error.main' : row.near ? 'warning.main' : 'text.primary' }}>
            {n(row.percent)}%
          </Typography>
        </Stack>
      )),
    },
    {
      key: 'state',
      label: t('super.usage.column.state'),
      render: (row) => (row.exhausted
        ? <Chip size="small" color="error" label={t('super.usage.state.over')} />
        : row.near ? <Chip size="small" color="warning" label={t('super.usage.state.near')} /> : null),
    },
  ];

  const total = series.reduce((sum, point) => sum + point.total, 0);
  const latest = series.length > 0 ? series[series.length - 1] : undefined;

  return (
    <Box>
      <Stack spacing={2}>
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ alignItems: { sm: 'center' } }}>
          <TextField
            select size="small" value={metric} label={t('super.usage.metric')}
            onChange={(e) => go({ metric: e.target.value, page: 1 })} sx={{ minWidth: 260 }}
          >
            {metrics.map((value) => <MenuItem key={value} value={value}>{metric_labels[value] ?? value}</MenuItem>)}
          </TextField>
          <Typography variant="body2" color="text.secondary">
            {t('super.usage.summary', {
              metric: label,
              total: amount(total),
              current: amount(latest?.total ?? 0),
              tenants: n(latest?.tenants ?? 0),
            })}
          </Typography>
        </Stack>

        <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: 'repeat(2, 1fr)', md: 'repeat(4, 1fr)' } }}>
          <Card variant="outlined"><CardContent sx={{ py: 1.5, '&:last-child': { pb: 1.5 } }}>
            <Typography variant="caption" color="text.secondary">{t('super.dashboard.tenants')}</Typography>
            <Typography variant="h6">{n(totals.tenants)}</Typography>
          </CardContent></Card>
          <Card variant="outlined"><CardContent sx={{ py: 1.5, '&:last-child': { pb: 1.5 } }}>
            <Typography variant="caption" color="text.secondary">{t('super.dashboard.appointments_month')}</Typography>
            <Typography variant="h6">{n(totals.appointments_this_month)}</Typography>
          </CardContent></Card>
          <Card variant="outlined"><CardContent sx={{ py: 1.5, '&:last-child': { pb: 1.5 } }}>
            <Typography variant="caption" color="text.secondary">{t('super.dashboard.sms_month')}</Typography>
            <Typography variant="h6">{n(totals.sms_this_month)}</Typography>
          </CardContent></Card>
          <Card variant="outlined"><CardContent sx={{ py: 1.5, '&:last-child': { pb: 1.5 } }}>
            <Typography variant="caption" color="text.secondary">{t('super.dashboard.mrr')}</Typography>
            <Typography variant="h6">{formatBdt(totals.mrr_paisa, locale)}</Typography>
          </CardContent></Card>
        </Box>

        <Card variant="outlined">
          <CardContent>
            <Typography variant="subtitle1" component="h2" gutterBottom>{t('super.usage.chart_title', { metric: label })}</Typography>
            <LazyChart height={260} empty={series.length === 0 || total === 0}>
              <UsageSeriesChart series={series} label={label} />
            </LazyChart>
          </CardContent>
        </Card>

        <Card variant="outlined">
          <CardContent sx={{ pb: 1 }}>
            <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5} sx={{ alignItems: { md: 'center' } }}>
              <Box sx={{ flexGrow: 1 }}>
                <Typography variant="subtitle1" component="h2">{t('super.usage.board_title', { metric: label })}</Typography>
                <Typography variant="caption" color="text.secondary">
                  {is_capped ? t('super.usage.board_help', { percent: n(near_percent) }) : t('super.usage.board_uncapped')}
                </Typography>
              </Box>
              <ToggleButtonGroup
                size="small" exclusive value={filters.filter} aria-label={t('super.usage.filter.label')}
                onChange={(_e, value: UsageBoardFilter | null) => { if (value !== null) go({ filter: value, page: 1 }); }}
              >
                <ToggleButton value="all">{t('super.usage.filter.all', { count: n(counts.all) })}</ToggleButton>
                <ToggleButton value="near" disabled={!is_capped}>{t('super.usage.filter.near', { count: n(counts.near) })}</ToggleButton>
                <ToggleButton value="over" disabled={!is_capped}>{t('super.usage.filter.over', { count: n(counts.over) })}</ToggleButton>
              </ToggleButtonGroup>
              <TextField
                select size="small" value={filters.sort} label={t('super.usage.sort.label')}
                onChange={(e) => go({ sort: e.target.value as UsageBoardSort, page: 1 })} sx={{ minWidth: 170 }}
              >
                <MenuItem value="percent">{t('super.usage.sort.percent')}</MenuItem>
                <MenuItem value="value">{t('super.usage.sort.value')}</MenuItem>
                <MenuItem value="name">{t('super.usage.sort.name')}</MenuItem>
              </TextField>
              <Button size="small" variant="outlined" startIcon={<DownloadIcon />} component="a" href={exportHref} disabled={meta.total === 0} data-testid="usage-export">
                {t('super.usage.export')}
              </Button>
            </Stack>
          </CardContent>
          <SuperTable
            columns={columns}
            rows={board}
            rowKey={(row) => row.public_id}
            empty={t(filters.filter === 'all' ? 'super.usage.empty' : 'super.usage.empty_filtered')}
            label={t('super.usage.board_title', { metric: label })}
            onRowClick={(row) => router.visit(route('super.usage.show', { tenant: row.public_id }))}
          />
          <Stack direction="row" spacing={1} sx={{ alignItems: 'center', justifyContent: 'flex-end', p: 1.5 }}>
            <Typography variant="body2" color="text.secondary">
              {t('super.tenants.page_of', { current: n(meta.current_page), last: n(meta.last_page), total: n(meta.total) })}
            </Typography>
            <Button size="small" startIcon={<ChevronLeftIcon />} disabled={meta.current_page <= 1} onClick={() => go({ page: meta.current_page - 1 })}>
              {t('super.actions.prev')}
            </Button>
            <Button size="small" endIcon={<ChevronRightIcon />} disabled={meta.current_page >= meta.last_page} onClick={() => go({ page: meta.current_page + 1 })}>
              {t('super.actions.next')}
            </Button>
          </Stack>
        </Card>
      </Stack>
    </Box>
  );
}

Index.layout = (page: ReactNode) => <PanelLayout title="super.usage.title">{page}</PanelLayout>;
