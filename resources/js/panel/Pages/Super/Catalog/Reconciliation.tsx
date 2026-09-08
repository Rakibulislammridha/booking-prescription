// The nightly catalogue reconciliation (SCHEMA §2.12): soft references from a clinic's schema into `catalog` that
// no longer resolve. Orphans are REPORTED, never deleted — a prescription naming a retired molecule is still what
// the doctor wrote — so the only action here is "a human has looked at this".
//
// Rows are grouped by run because that is how they are produced and how they are read: one night, one sweep.
import { useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Collapse from '@mui/material/Collapse';
import FormControlLabel from '@mui/material/FormControlLabel';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import Typography from '@mui/material/Typography';
import ChevronLeftIcon from '@mui/icons-material/ChevronLeft';
import ChevronRightIcon from '@mui/icons-material/ChevronRight';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { SuperNav } from '@panel/Components/Super/SuperNav';
import { SuperTable, type SuperColumn } from '@panel/Components/Super/SuperTable';
import type { ConsoleMeta, ReconciliationRow } from '@panel/Components/Super/types';
import { formatNumber } from '@shared/format/number';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { hasRoute, route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{
  reports: ReconciliationRow[];
  meta: ConsoleMeta;
  filters: { unresolved: boolean };
}>;

interface RunGroup {
  run_id: string;
  created_at: string | null;
  rows: ReconciliationRow[];
}

function group(reports: ReconciliationRow[]): RunGroup[] {
  const groups: RunGroup[] = [];

  for (const report of reports) {
    const last = groups[groups.length - 1];
    if (last !== undefined && last.run_id === report.run_id) last.rows.push(report);
    else groups.push({ run_id: report.run_id, created_at: report.created_at, rows: [report] });
  }

  return groups;
}

export default function Reconciliation({ reports, meta, filters }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [open, setOpen] = useState<number | null>(null);

  const go = (params: { unresolved?: boolean; page?: number }): void => {
    const unresolved = params.unresolved ?? filters.unresolved;
    const query: Record<string, string | number> = { unresolved: unresolved ? 1 : 0 };
    if (params.page !== undefined && params.page > 1) query.page = params.page;
    router.get(route('super.catalog.reconciliation.index'), query, { preserveState: true, replace: true, preserveScroll: true });
  };

  const resolve = (report: ReconciliationRow): void => {
    router.post(route('super.catalog.reconciliation.resolve', { report: report.id }), {}, { preserveScroll: true });
  };

  const columns: SuperColumn<ReconciliationRow>[] = [
    {
      key: 'tenant',
      label: t('super.reconciliation.column.clinic'),
      bn: true,
      render: (row) => (
        row.tenant === null ? <Typography variant="body2" color="text.secondary">{t('super.reconciliation.no_tenant')}</Typography> : (
          hasRoute('super.tenants.show') ? (
            <Box component={RouterLink} href={route('super.tenants.show', { tenant: row.tenant.public_id })} sx={{ color: 'primary.main', fontWeight: 600, textDecoration: 'none' }}>
              {row.tenant.name}
            </Box>
          ) : <Typography variant="body2" sx={{ fontWeight: 600 }}>{row.tenant.name}</Typography>
        )
      ),
    },
    {
      key: 'reference',
      label: t('super.reconciliation.column.reference'),
      render: (row) => <Box sx={{ fontFamily: 'monospace', fontSize: 13 }}>{row.table_name}.{row.column_name}</Box>,
    },
    { key: 'checked', label: t('super.reconciliation.column.checked'), align: 'right', render: (row) => formatNumber(row.checked_count, locale) },
    {
      key: 'orphans',
      label: t('super.reconciliation.column.orphans'),
      align: 'right',
      render: (row) => (
        <Typography variant="body2" sx={{ color: row.orphan_count > 0 ? 'error.main' : 'text.secondary', fontVariantNumeric: 'tabular-nums' }}>
          {formatNumber(row.orphan_count, locale)}
        </Typography>
      ),
    },
    {
      key: 'status',
      label: t('super.reconciliation.column.status'),
      render: (row) => (
        <Stack direction="row" spacing={0.5} sx={{ alignItems: 'center' }}>
          <Chip
            size="small"
            variant="outlined"
            color={row.status === 'clean' ? 'success' : row.orphan_count > 0 ? 'error' : 'warning'}
            label={t(`super.reconciliation.status.${row.status}`, { defaultValue: row.status })}
          />
          {row.resolved_at !== null ? (
            <Chip size="small" color="success" label={t('super.reconciliation.reviewed')} />
          ) : null}
        </Stack>
      ),
    },
    {
      key: 'actions',
      label: t('super.reconciliation.column.actions'),
      align: 'right',
      render: (row) => (
        <Stack direction="row" spacing={0.5} sx={{ justifyContent: 'flex-end' }}>
          <Button size="small" onClick={() => setOpen(open === row.id ? null : row.id)}>
            {open === row.id ? t('super.reconciliation.hide_samples') : t('super.reconciliation.samples')}
          </Button>
          <Button size="small" variant="outlined" onClick={() => resolve(row)} disabled={row.resolved_at !== null}>
            {t('super.reconciliation.mark_reviewed')}
          </Button>
        </Stack>
      ),
    },
  ];

  const groups = group(reports);

  return (
    <Box>
      <SuperNav />

      <Stack spacing={2}>
        <Stack direction="row" spacing={2} sx={{ alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap' }} useFlexGap>
          <Box>
            <Typography variant="subtitle1" component="h2">{t('super.reconciliation.title')}</Typography>
            <Typography variant="caption" color="text.secondary">{t('super.reconciliation.subtitle')}</Typography>
          </Box>
          <FormControlLabel
            control={<Switch checked={filters.unresolved} onChange={(e) => go({ unresolved: e.target.checked, page: 1 })} />}
            label={t('super.reconciliation.unresolved_only')}
          />
        </Stack>

        {groups.length === 0 ? (
          <Card variant="outlined">
            <CardContent>
              <Typography variant="body2" color="text.secondary" sx={{ py: 3, textAlign: 'center' }}>{t('super.reconciliation.empty')}</Typography>
            </CardContent>
          </Card>
        ) : groups.map((run) => (
          <Card key={run.run_id} variant="outlined">
            <CardContent sx={{ py: 1.5, '&:last-child': { pb: 1.5 } }}>
              <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap' }} useFlexGap>
                <Typography variant="subtitle2">{t('super.reconciliation.run')}</Typography>
                <Box sx={{ fontFamily: 'monospace', fontSize: 12, color: 'text.secondary' }}>{run.run_id}</Box>
                <Typography variant="caption" color="text.secondary">
                  {run.created_at === null ? '—' : formatDhaka(run.created_at, 'D MMM YYYY, h:mm a', locale)}
                </Typography>
                <Chip size="small" variant="outlined" label={t('super.reconciliation.rows', { count: formatNumber(run.rows.length, locale) })} />
              </Stack>
            </CardContent>
            <SuperTable
              columns={columns}
              rows={run.rows}
              rowKey={(row) => String(row.id)}
              empty={t('super.reconciliation.empty')}
              label={t('super.reconciliation.title')}
            />
            {run.rows.map((row) => (
              <Collapse key={row.id} in={open === row.id} unmountOnExit>
                <Box sx={{ px: 2, pb: 2 }}>
                  <Typography variant="caption" color="text.secondary">{t('super.reconciliation.sample_ids')}</Typography>
                  <Box component="pre" sx={{ m: 0, p: 1, bgcolor: 'action.hover', borderRadius: 1, fontSize: 11, overflowX: 'auto', maxHeight: 200 }}>
                    {JSON.stringify(row.sample_ids, null, 2)}
                  </Box>
                  {row.details === null || row.details === undefined ? null : (
                    <>
                      <Typography variant="caption" color="text.secondary">{t('super.reconciliation.details')}</Typography>
                      <Box component="pre" sx={{ m: 0, p: 1, bgcolor: 'action.hover', borderRadius: 1, fontSize: 11, overflowX: 'auto', maxHeight: 200 }}>
                        {JSON.stringify(row.details, null, 2)}
                      </Box>
                    </>
                  )}
                </Box>
              </Collapse>
            ))}
          </Card>
        ))}

        <Stack direction="row" spacing={1} sx={{ alignItems: 'center', justifyContent: 'flex-end' }}>
          <Typography variant="body2" color="text.secondary">
            {t('super.tenants.page_of', {
              current: formatNumber(meta.current_page, locale),
              last: formatNumber(meta.last_page, locale),
              total: formatNumber(meta.total, locale),
            })}
          </Typography>
          <Button size="small" startIcon={<ChevronLeftIcon />} disabled={meta.current_page <= 1} onClick={() => go({ page: meta.current_page - 1 })}>
            {t('super.actions.prev')}
          </Button>
          <Button size="small" endIcon={<ChevronRightIcon />} disabled={meta.current_page >= meta.last_page} onClick={() => go({ page: meta.current_page + 1 })}>
            {t('super.actions.next')}
          </Button>
        </Stack>
      </Stack>
    </Box>
  );
}

Reconciliation.layout = (page: ReactNode) => <PanelLayout title="super.reconciliation.title">{page}</PanelLayout>;
