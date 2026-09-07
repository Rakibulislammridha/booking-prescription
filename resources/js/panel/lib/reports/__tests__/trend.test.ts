import { describe, expect, it } from 'vitest';
import { pivotTrend } from '../trend';
import { query } from '@panel/Components/Reports/FilterBar';
import type { ReportFilterState, ReportTrendPoint } from '@shared/types/models';

describe('pivotTrend', () => {
  const points: ReportTrendPoint[] = [
    { period: '2026-03-02', key: 'E11', visits: 4 },
    { period: '2026-03-01', key: 'E11', visits: 3 },
    { period: '2026-03-01', key: 'I10', visits: 2 },
  ];

  it('turns one row per (period, key) into one row per period', () => {
    expect(pivotTrend(points, ['E11', 'I10'], 'visits')).toEqual([
      { period: '2026-03-01', E11: 3, I10: 2 },
      { period: '2026-03-02', E11: 4, I10: 0 },
    ]);
  });

  it('fills a missing series with zero rather than leaving a gap in the line', () => {
    const march2 = pivotTrend(points, ['E11', 'I10', 'K29.7'], 'visits').find((row) => row.period === '2026-03-02');
    expect(march2).toBeDefined();
    expect(march2?.['K29.7']).toBe(0);
    expect(march2?.I10).toBe(0);
  });

  it('is empty for no points', () => {
    expect(pivotTrend([], ['E11'], 'visits')).toEqual([]);
  });
});

describe('query', () => {
  const filters: ReportFilterState = {
    from: '2026-03-01', to: '2026-03-31',
    branch_id: null, doctor_id: null, specialty_id: null, method: null,
    metric: 'arrivals', limit: 15, granularity: 'day',
    branch: 'BRANCHULID', doctor: null, specialty: null,
  };

  it('carries the current filters and drops the empty ones', () => {
    expect(query(filters)).toEqual({
      from: '2026-03-01', to: '2026-03-31', branch: 'BRANCHULID', metric: 'arrivals',
    });
  });

  it('overrides one filter without losing the rest, and a null clears it', () => {
    expect(query(filters, { doctor: 'DOCULID' })).toMatchObject({ branch: 'BRANCHULID', doctor: 'DOCULID' });
    expect(query(filters, { branch: null })).not.toHaveProperty('branch');
  });
});
