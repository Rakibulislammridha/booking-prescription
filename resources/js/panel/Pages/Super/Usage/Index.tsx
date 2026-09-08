// One metric across the whole platform for twelve months, plus the clinics carrying most of it. The point of the
// screen is capacity planning and pricing: "we sell 5,000 SMS a month and the platform sends 41,000".
import { type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip as RTooltip, XAxis, YAxis } from 'recharts';
import { useTheme } from '@mui/material/styles';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { SuperNav } from '@panel/Components/Super/SuperNav';
import { SuperTable, type SuperColumn } from '@panel/Components/Super/SuperTable';
import { StatusChip } from '@panel/Components/Super/StatusChip';
import { bytesParts } from '@panel/Components/Super/UsageBars';
import type { PlatformTotals, UsageSeriesPoint, UsageTopRow } from '@panel/Components/Super/types';
import { formatBdt } from '@shared/format/money';
import { formatNumber } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import { hasRoute, route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{
  metric: string;
  metrics: string[];
  metric_labels: Record<string, string>;
  is_bytes: boolean;
  series: UsageSeriesPoint[];
  top: UsageTopRow[];
  totals: PlatformTotals;
}>;

export default function Index({ metric, metrics, metric_labels, is_bytes, series, top, totals }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const theme = useTheme();
  const label = metric_labels[metric] ?? metric;

  const amount = (value: number): string => {
    if (!is_bytes) return formatNumber(value, locale);
    const parts = bytesParts(value, locale);
    return t(parts.key, { size: parts.size });
  };

  const change = (next: string): void => {
    router.get(route('super.usage.index'), { metric: next }, { preserveState: true, replace: true });
  };

  const columns: SuperColumn<UsageTopRow>[] = [
    {
      key: 'clinic',
      label: t('super.usage.column.clinic'),
      bn: true,
      render: (row) => (
        <Box>
          {hasRoute('super.tenants.show') ? (
            <Box component={RouterLink} href={route('super.tenants.show', { tenant: row.public_id })} sx={{ color: 'primary.main', fontWeight: 600, textDecoration: 'none' }}>
              {row.name}
            </Box>
          ) : <Typography variant="body2" sx={{ fontWeight: 600 }}>{row.name}</Typography>}
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{row.slug}</Typography>
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
      render: (row) => (row.limit === null || row.limit === 0 ? '—' : `${formatNumber(Math.round((row.value / row.limit) * 100), locale)}%`),
    },
  ];

  const total = series.reduce((sum, point) => sum + point.total, 0);
  const latest = series.length > 0 ? series[series.length - 1] : undefined;

  return (
    <Box>
      <SuperNav />

      <Stack spacing={2}>
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ alignItems: { sm: 'center' } }}>
          <TextField
            select size="small" value={metric} label={t('super.usage.metric')}
            onChange={(e) => change(e.target.value)} sx={{ minWidth: 260 }}
          >
            {metrics.map((value) => <MenuItem key={value} value={value}>{metric_labels[value] ?? value}</MenuItem>)}
          </TextField>
          <Typography variant="body2" color="text.secondary">
            {t('super.usage.summary', {
              metric: label,
              total: amount(total),
              current: amount(latest?.total ?? 0),
              tenants: formatNumber(latest?.tenants ?? 0, locale),
            })}
          </Typography>
        </Stack>

        <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: 'repeat(2, 1fr)', md: 'repeat(4, 1fr)' } }}>
          <Card variant="outlined"><CardContent sx={{ py: 1.5, '&:last-child': { pb: 1.5 } }}>
            <Typography variant="caption" color="text.secondary">{t('super.dashboard.tenants')}</Typography>
            <Typography variant="h6">{formatNumber(totals.tenants, locale)}</Typography>
          </CardContent></Card>
          <Card variant="outlined"><CardContent sx={{ py: 1.5, '&:last-child': { pb: 1.5 } }}>
            <Typography variant="caption" color="text.secondary">{t('super.dashboard.appointments_month')}</Typography>
            <Typography variant="h6">{formatNumber(totals.appointments_this_month, locale)}</Typography>
          </CardContent></Card>
          <Card variant="outlined"><CardContent sx={{ py: 1.5, '&:last-child': { pb: 1.5 } }}>
            <Typography variant="caption" color="text.secondary">{t('super.dashboard.sms_month')}</Typography>
            <Typography variant="h6">{formatNumber(totals.sms_this_month, locale)}</Typography>
          </CardContent></Card>
          <Card variant="outlined"><CardContent sx={{ py: 1.5, '&:last-child': { pb: 1.5 } }}>
            <Typography variant="caption" color="text.secondary">{t('super.dashboard.mrr')}</Typography>
            <Typography variant="h6">{formatBdt(totals.mrr_paisa, locale)}</Typography>
          </CardContent></Card>
        </Box>

        <Card variant="outlined">
          <CardContent>
            <Typography variant="subtitle1" component="h2" gutterBottom>{t('super.usage.chart_title', { metric: label })}</Typography>
            <Box sx={{ height: 260 }}>
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={series} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
                  <CartesianGrid stroke={theme.palette.divider} strokeDasharray="3 3" />
                  <XAxis dataKey="period" tick={{ fill: theme.palette.text.secondary, fontSize: 11 }} />
                  <YAxis tick={{ fill: theme.palette.text.secondary, fontSize: 11 }} width={56} allowDecimals={false} />
                  <RTooltip
                    contentStyle={{
                      backgroundColor: theme.palette.background.paper,
                      border: `1px solid ${theme.palette.divider}`,
                      borderRadius: 6,
                      color: theme.palette.text.primary,
                      fontSize: 12,
                    }}
                  />
                  <Bar dataKey="total" name={label} fill={theme.palette.primary.main} radius={[3, 3, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </Box>
          </CardContent>
        </Card>

        <Card variant="outlined">
          <CardContent sx={{ pb: 0 }}>
            <Typography variant="subtitle1" component="h2">{t('super.usage.top_title')}</Typography>
          </CardContent>
          <SuperTable columns={columns} rows={top} rowKey={(row) => row.public_id} empty={t('super.usage.empty')} label={t('super.usage.top_title')} />
        </Card>
      </Stack>
    </Box>
  );
}

Index.layout = (page: ReactNode) => <PanelLayout title="super.usage.title">{page}</PanelLayout>;
