// Super/Usage/Show — one clinic's counters against its plan, every metric, with six months of history: the
// answer to "is this clinic about to hit a wall, and is that new". Gauges (doctors, branches, storage) are a
// single current reading; meters get their history drawn.
import { lazy, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import LinearProgress from '@mui/material/LinearProgress';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import LaunchIcon from '@mui/icons-material/Launch';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { LazyChart } from '@panel/Components/Charts/LazyChart';
import { SuperNav } from '@panel/Components/Super/SuperNav';
import { StatusChip } from '@panel/Components/Super/StatusChip';
import { bytesParts } from '@panel/Components/Super/UsageBars';
import type { TenantUsageMetric, UsageTenantRef } from '@panel/Components/Super/types';
import { formatNumber } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import { hasRoute, route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{
  tenant: UsageTenantRef;
  metrics: TenantUsageMetric[];
  metric_labels: Record<string, string>;
  near_percent: number;
  history_months: number;
}>;

const TenantHistoryChart = lazy(() => import('@panel/Components/Charts/SuperCharts').then((m) => ({ default: m.TenantHistoryChart })));

export default function Show({ tenant, metrics, metric_labels, near_percent, history_months }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const n = (value: number): string => formatNumber(value, locale);

  const amount = (value: number, isBytes: boolean): string => {
    if (!isBytes) return n(value);
    const parts = bytesParts(value, locale);
    return t(parts.key, { size: parts.size });
  };

  const capped = metrics.filter((m) => m.capped);
  const uncapped = metrics.filter((m) => !m.capped);

  const card = (row: TenantUsageMetric): ReactNode => {
    const label = metric_labels[row.metric] ?? row.metric;
    const unlimited = row.limit === null;
    const hasHistory = !row.is_gauge && row.history.some((p) => p.value > 0);

    return (
      <Card key={row.metric} variant="outlined" data-testid={`usage-metric-${row.metric}`}>
        <CardContent>
          <Stack direction="row" spacing={1} sx={{ alignItems: 'baseline', justifyContent: 'space-between', flexWrap: 'wrap' }}>
            <Typography variant="subtitle1" component="h3">{label}</Typography>
            <Stack direction="row" spacing={0.75} sx={{ alignItems: 'center' }}>
              <Typography variant="h6" sx={{ fontVariantNumeric: 'tabular-nums' }}>{amount(row.used, row.is_bytes)}</Typography>
              <Typography variant="body2" color="text.secondary">
                {row.capped ? (unlimited ? t('saas.pricing.unlimited') : `/ ${amount(row.limit ?? 0, row.is_bytes)}`) : t('super.usage.uncapped')}
              </Typography>
              {row.exhausted ? <Chip size="small" color="error" label={t('super.usage.state.over')} /> : row.near ? <Chip size="small" color="warning" label={t('super.usage.state.near')} /> : null}
            </Stack>
          </Stack>
          <Typography variant="caption" color="text.secondary">
            {row.is_gauge ? t('super.usage.period_now') : t('super.usage.period_month', { period: row.period })}
            {row.capped && !unlimited && row.percent !== null ? ` · ${t('super.usage.percent_of_limit', { percent: n(row.percent) })}` : ''}
            {row.capped && row.remaining !== null ? ` · ${t('super.usage.remaining', { count: amount(row.remaining, row.is_bytes) })}` : ''}
          </Typography>
          {row.capped ? (
            <LinearProgress
              variant="determinate"
              value={unlimited ? 0 : Math.min(100, Math.max(0, row.percent ?? 0))}
              color={row.exhausted ? 'error' : row.near ? 'warning' : 'primary'}
              aria-label={label}
              sx={{ height: 6, borderRadius: 1, mt: 1, opacity: unlimited ? 0.35 : 1 }}
            />
          ) : null}
          {row.is_gauge ? null : (
            <Box sx={{ mt: 1.5 }}>
              <Typography variant="caption" color="text.secondary">{t('super.usage.history_title', { months: n(history_months) })}</Typography>
              <Stack direction="row" spacing={1} useFlexGap sx={{ flexWrap: 'wrap', mt: 0.5, mb: 1 }}>
                {row.history.map((point) => (
                  <Chip key={point.period} size="small" variant="outlined" label={`${point.period} · ${amount(point.value, row.is_bytes)}`} />
                ))}
              </Stack>
              <LazyChart height={140} empty={!hasHistory} emptyLabel={t('super.usage.history_empty')}>
                <TenantHistoryChart points={row.history} label={label} />
              </LazyChart>
            </Box>
          )}
        </CardContent>
      </Card>
    );
  };

  return (
    <Box>
      <SuperNav />

      <Stack spacing={2} sx={{ maxWidth: 960 }}>
        <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
          <Button size="small" startIcon={<ArrowBackIcon />} component={RouterLink} href={route('super.usage.index')}>{t('super.usage.back')}</Button>
          <Typography variant="h6" component="h2" lang="bn" sx={{ flexGrow: 1 }}>{tenant.name}</Typography>
          <StatusChip status={tenant.status} />
          <Chip size="small" variant="outlined" label={tenant.plan_name} />
          {hasRoute('super.tenants.show') ? (
            <Button size="small" endIcon={<LaunchIcon />} component={RouterLink} href={route('super.tenants.show', { tenant: tenant.public_id })}>
              {t('super.usage.open_tenant')}
            </Button>
          ) : null}
        </Stack>
        <Typography variant="body2" color="text.secondary">{t('super.usage.detail_help', { percent: n(near_percent) })}</Typography>

        <Typography variant="overline" color="text.secondary">{t('super.usage.capped_title')}</Typography>
        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', md: 'repeat(2, 1fr)' } }}>
          {capped.map(card)}
        </Box>

        {uncapped.length > 0 ? (
          <>
            <Typography variant="overline" color="text.secondary">{t('super.usage.uncapped_title')}</Typography>
            <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', md: 'repeat(2, 1fr)' } }}>
              {uncapped.map(card)}
            </Box>
          </>
        ) : null}
      </Stack>
    </Box>
  );
}

Show.layout = (page: ReactNode) => <PanelLayout title="super.usage.title">{page}</PanelLayout>;
