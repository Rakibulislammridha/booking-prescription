// Long-form trend rows → the wide shape recharts wants. The server returns one row per (period, key) because
// that is what a GROUP BY produces and what streams cheaply; a chart needs one object per period with a column
// per series, and a missing (period, key) pair has to become an explicit 0 or the line simply stops.
import type { ReportTrendPoint } from '@shared/types/models';

export type TrendRow = Record<string, string | number>;

export function pivotTrend(points: ReportTrendPoint[], keys: string[], field: 'visits' | 'items'): TrendRow[] {
  const byPeriod = new Map<string, TrendRow>();

  for (const point of points) {
    const row = byPeriod.get(point.period) ?? { period: point.period };
    row[point.key] = point[field] ?? 0;
    byPeriod.set(point.period, row);
  }

  return [...byPeriod.values()]
    .map((row) => {
      for (const key of keys) if (!(key in row)) row[key] = 0;
      return row;
    })
    .sort((a, b) => String(a.period).localeCompare(String(b.period)));
}
