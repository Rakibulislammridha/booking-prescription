// Queued exports (BRIEF §5.L). A report small enough to stream never lands here; this is where a clinic's
// three years comes back once Horizon's `reports` queue has written the file.
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import DownloadIcon from '@mui/icons-material/Download';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { ChartCard } from '@panel/Components/Reports/ChartCard';
import { DataTable, type Column } from '@panel/Components/Reports/DataTable';
import { formatDhaka } from '@shared/format/date';
import { formatBn } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type { ReportExportRow } from '@shared/types/models';

type Props = PageProps<{ exports: ReportExportRow[] }>;

const TONE: Record<ReportExportRow['status'], 'default' | 'info' | 'success' | 'error'> = {
  pending: 'default', processing: 'info', ready: 'success', failed: 'error',
};

export default function Exports({ exports }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();

  const columns: Column<ReportExportRow>[] = [
    { key: 'report', label: t('reports.column.report'), render: (r) => t(`reports.${r.report.replace('-', '_')}.title`, { defaultValue: r.title }) },
    { key: 'format', label: t('reports.column.format'), render: (r) => t(`reports.export.${r.format}`) },
    { key: 'range', label: t('reports.column.period'), render: (r) => `${String(r.filters.from ?? '—')} → ${String(r.filters.to ?? '—')}` },
    { key: 'status', label: t('reports.column.status'), render: (r) => <Chip size="small" color={TONE[r.status]} variant="outlined" label={t(`reports.export.status.${r.status}`)} /> },
    { key: 'rows', label: t('reports.column.rows'), align: 'right', render: (r) => (r.row_count === null ? '—' : formatBn(r.row_count, locale)) },
    { key: 'created', label: t('reports.column.requested'), render: (r) => (r.created_at ? formatDhaka(r.created_at, 'D MMM, h:mm a', locale) : '—') },
    {
      key: 'download', label: '', align: 'right',
      render: (r) => (r.downloadable
        ? <Button size="small" startIcon={<DownloadIcon />} href={route('panel.reports.exports.show', { export: r.public_id })}>{t('reports.export.download')}</Button>
        : <Typography variant="caption" color="text.secondary">{r.error ?? '—'}</Typography>),
    },
  ];

  return (
    <Stack spacing={2}>
      <ChartCard title={t('reports.exports.title')} subtitle={t('reports.exports.hint')}>
        <DataTable columns={columns} rows={exports} rowKey={(r) => r.public_id} emptyLabel={t('reports.exports.empty')} />
      </ChartCard>
    </Stack>
  );
}

Exports.layout = (page: ReactNode) => <PanelLayout title="reports.exports.title">{page}</PanelLayout>;
