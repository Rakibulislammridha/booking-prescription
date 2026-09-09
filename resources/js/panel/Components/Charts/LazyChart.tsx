// Every recharts drawing on the panel is behind this wrapper (CONVENTIONS §7.3, scripts/check-panel-budget.sh).
//
// recharts is ~97 KB gzip — a quarter of the whole staff bundle — and it used to sit in the STATIC import graph
// of eight pages, so a reception desk on a cheap Android tablet paid for it on Reports pages it may never open
// and, worse, the Reports pages themselves paid for it before the server had said whether there was anything to
// draw. The charts now live in `Components/Charts/*.tsx`, are reached only through `React.lazy`, and are mounted
// only when there ARE rows: a range with no data renders a sentence and never touches the chart chunk at all.
//
//   const ByDayChart = lazy(() => import('@panel/Components/Charts/RevenueCharts').then((m) => ({ default: m.ByDayChart })));
//   <LazyChart height={260} empty={rows.length === 0} emptyLabel={t('reports.empty')}><ByDayChart rows={rows} /></LazyChart>
//
// `children` is an element DESCRIPTION, not a render: returning early on `empty` means the lazy component never
// mounts, so its chunk is never requested.
import { Suspense, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Skeleton from '@mui/material/Skeleton';
import Typography from '@mui/material/Typography';

export interface LazyChartProps {
  /** Same height the chart's own ResponsiveContainer fills, so the skeleton does not shift the layout. */
  height: number;
  /** True when there is nothing to draw. The chart chunk is not loaded in that case. */
  empty: boolean;
  /** Defaults to the generic `common.status.no_data`; Reports pages pass their own `reports.empty`. */
  emptyLabel?: string;
  children: ReactNode;
}

export function LazyChart({ height, empty, emptyLabel, children }: LazyChartProps) {
  const { t } = useTranslation();

  if (empty) {
    return (
      <Box sx={{ height, display: 'flex', alignItems: 'center', justifyContent: 'center' }} data-testid="chart-empty">
        <Typography variant="body2" color="text.secondary">{emptyLabel ?? t('common.status.no_data')}</Typography>
      </Box>
    );
  }

  return (
    <Box sx={{ height }}>
      <Suspense fallback={<Skeleton variant="rounded" height={height} />}>{children}</Suspense>
    </Box>
  );
}

export default LazyChart;
