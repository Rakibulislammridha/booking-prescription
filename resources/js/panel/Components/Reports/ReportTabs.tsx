// Navigation between the report families. Only the ones this user's scope allows are rendered, so an
// accountant never sees a "Clinical" tab that would 403 and a doctor never sees "Revenue".
import { useTranslation } from 'react-i18next';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import { router } from '@inertiajs/react';
import { route } from '@shared/routes';
import type { ReportFilterState, ReportKind, ReportScopeProps } from '@shared/types/models';
import { query } from './FilterBar';

const LABELS: Record<ReportKind, string> = {
  dashboard: 'reports.dashboard.title',
  appointments: 'reports.appointments.title',
  'wait-times': 'reports.wait_times.title',
  revenue: 'reports.revenue.title',
  patients: 'reports.patients.title',
  clinical: 'reports.clinical.title',
  'peak-hours': 'reports.peak_hours.title',
};

export function ReportTabs({ current, scope, filters }: { current: ReportKind; scope: ReportScopeProps; filters: ReportFilterState }) {
  const { t } = useTranslation();
  const kinds = scope.reports.length > 0 ? scope.reports : (['dashboard'] as ReportKind[]);
  const value = kinds.includes(current) ? current : kinds[0];

  const go = (kind: ReportKind): void => {
    const target = kind === 'dashboard' ? route('panel.reports.index') : route('panel.reports.show', { report: kind });
    router.get(target, query(filters), { preserveState: false });
  };

  return (
    <Tabs value={value} onChange={(_, next: ReportKind) => go(next)} variant="scrollable" scrollButtons="auto" sx={{ minHeight: 40, mb: 1 }}>
      {kinds.map((kind) => <Tab key={kind} value={kind} label={t(LABELS[kind])} sx={{ minHeight: 40, py: 0 }} />)}
    </Tabs>
  );
}
