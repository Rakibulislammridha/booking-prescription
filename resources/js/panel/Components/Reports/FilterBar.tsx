// The filter bar every report page shares: range, branch, doctor, and whatever extra control the page needs.
// Changing a control is an Inertia GET with `preserveState`, so the page keeps its scroll position and the
// server re-runs one cached aggregate rather than the client filtering rows it never had.
//
// The doctor select is hidden for a scope that has only its own doctor: the server would overwrite the filter
// anyway, and offering a control that does nothing is worse than not offering it.
import type { ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Chip from '@mui/material/Chip';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import CachedIcon from '@mui/icons-material/Cached';
import { route } from '@shared/routes';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import type { ReportFilterState, ReportKind, ReportOptionsProps, ReportScopeProps } from '@shared/types/models';

export interface FilterBarProps {
  report: ReportKind;
  filters: ReportFilterState;
  options: ReportOptionsProps;
  scope: ReportScopeProps;
  generatedAt: string;
  cached: boolean;
  /** Page-specific controls (the heatmap's metric switch, the clinical page's specialty select). */
  extra?: ReactNode;
  action?: ReactNode;
}

/** Presets a clinic actually asks for, expressed as day offsets from today. */
const PRESETS: { key: string; days: number }[] = [
  { key: 'today', days: 0 },
  { key: 'week', days: 6 },
  { key: 'month', days: 29 },
  { key: 'quarter', days: 89 },
  { key: 'year', days: 364 },
];

export function query(filters: ReportFilterState, next: Record<string, string | null> = {}): Record<string, string> {
  const base: Record<string, string | null> = {
    from: filters.from,
    to: filters.to,
    branch: filters.branch ?? null,
    doctor: filters.doctor ?? null,
    specialty: filters.specialty ?? null,
    metric: filters.metric,
    ...next,
  };

  const out: Record<string, string> = {};
  for (const [key, value] of Object.entries(base)) if (value !== null && value !== '') out[key] = value;
  return out;
}

export function FilterBar({ report, filters, options, scope, generatedAt, cached, extra, action }: FilterBarProps) {
  const { t } = useTranslation();
  const locale = getLocale();
  const target = report === 'dashboard' ? route('panel.reports.index') : route('panel.reports.show', { report });

  const go = (next: Record<string, string | null>): void => {
    router.get(target, query(filters, next), { preserveState: true, preserveScroll: true, replace: true });
  };

  const preset = (days: number): void => {
    const to = new Date();
    const from = new Date(to.getTime() - days * 86400000);
    const iso = (d: Date): string => d.toISOString().slice(0, 10);
    go({ from: iso(from), to: iso(to) });
  };

  return (
    <Stack spacing={1}>
      <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap', gap: 1, alignItems: 'center' }}>
        <TextField
          type="date" size="small" label={t('reports.filter.from')} value={filters.from}
          onChange={(e) => e.target.value && go({ from: e.target.value })}
          slotProps={{ inputLabel: { shrink: true } }} sx={{ minWidth: 150 }}
        />
        <TextField
          type="date" size="small" label={t('reports.filter.to')} value={filters.to}
          onChange={(e) => e.target.value && go({ to: e.target.value })}
          slotProps={{ inputLabel: { shrink: true } }} sx={{ minWidth: 150 }}
        />
        {options.branches.length > 1 ? (
          <TextField select size="small" sx={{ minWidth: 170 }} label={t('reports.filter.branch')} value={filters.branch ?? ''} onChange={(e) => go({ branch: e.target.value || null })}>
            <MenuItem value="">{t('reports.filter.all_branches')}</MenuItem>
            {options.branches.map((b) => <MenuItem key={b.public_id} value={b.public_id} lang="bn">{b.name}</MenuItem>)}
          </TextField>
        ) : null}
        {scope.all_doctors ? (
          <TextField select size="small" sx={{ minWidth: 190 }} label={t('reports.filter.doctor')} value={filters.doctor ?? ''} onChange={(e) => go({ doctor: e.target.value || null })}>
            <MenuItem value="">{t('reports.filter.all_doctors')}</MenuItem>
            {options.doctors.map((d) => <MenuItem key={d.public_id} value={d.public_id} lang="bn">{d.name}</MenuItem>)}
          </TextField>
        ) : (
          <Chip size="small" variant="outlined" label={t('reports.filter.own_only')} />
        )}
        {extra}
        <Stack sx={{ flexGrow: 1 }} />
        {action}
      </Stack>

      <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap', gap: 0.5, alignItems: 'center' }}>
        {PRESETS.map((p) => (
          <Chip key={p.key} size="small" label={t(`reports.range.${p.key}`)} onClick={() => preset(p.days)} variant="outlined" />
        ))}
        <Stack sx={{ flexGrow: 1 }} />
        {/* A cached number is never presented as a live one (ReportCache): the moment it was computed is on screen. */}
        <Tooltip title={t(cached ? 'reports.generated.cached' : 'reports.generated.fresh')}>
          <Stack direction="row" spacing={0.5} sx={{ alignItems: 'center' }}>
            <CachedIcon sx={{ fontSize: 14, color: 'text.disabled' }} />
            <Typography variant="caption" color="text.secondary">
              {t('reports.generated.at', { time: formatDhaka(generatedAt, 'D MMM, h:mm a', locale) })}
            </Typography>
          </Stack>
        </Tooltip>
      </Stack>
    </Stack>
  );
}
