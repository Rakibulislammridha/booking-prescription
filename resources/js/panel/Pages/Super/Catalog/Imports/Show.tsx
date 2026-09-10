// One uploaded bundle and what happened to it: the files, the dry-run report (adds / updates / deactivations
// per table, issues by kind with the first samples), the apply button, and — once applied — the version it
// created and its `catalog_import_issues`. Polls while the job is queued or running; nothing here runs an import.
import { useEffect, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import LinearProgress from '@mui/material/LinearProgress';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { SuperNav } from '@panel/Components/Super/SuperNav';
import { SuperTable, type SuperColumn } from '@panel/Components/Super/SuperTable';
import { JobStatusChip } from '@panel/Components/Super/Catalog/JobStatusChip';
import type { CatalogImportIssueRow, CatalogJobRow, CatalogVersionRow, ImportIssueSample } from '@panel/Components/Super/types';
import { formatNumber } from '@shared/format/number';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{
  job: CatalogJobRow;
  issues: CatalogImportIssueRow[];
  version: CatalogVersionRow | null;
  /** A release that already carries this bundle's checksum: the buttons then run with `force`. */
  known_version: string | null;
}>;

const POLL_MS = 2000;

interface CountRow {
  table: string;
  rows: number;
  inserted: number;
  updated: number;
  deactivated: number;
}

interface SampleRow extends ImportIssueSample {
  key: string;
}

function describe(payload: Record<string, unknown>): string {
  const parts = ['brand', 'generic_text', 'strength_label', 'form_text', 'manufacturer', 'file', 'reason', 'existing_manufacturer']
    .map((k) => (typeof payload[k] === 'string' && payload[k] !== '' ? `${k}: ${String(payload[k])}` : null))
    .filter((p): p is string => p !== null);
  return parts.join(' · ');
}

export default function Show({ job, issues, version, known_version }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const n = (v: number | null | undefined): string => (v === null || v === undefined ? '—' : formatNumber(v, locale));
  const unfinished = job.status === 'queued' || job.status === 'running';
  const report = job.report;

  useEffect(() => {
    if (!unfinished) return;
    const timer = window.setInterval(() => router.reload({ only: ['job', 'issues', 'version'] }), POLL_MS);
    return () => window.clearInterval(timer);
  }, [unfinished]);

  const queue = (which: 'dry-run' | 'apply', force = false): void => {
    router.post(route(which === 'apply' ? 'super.catalog.imports.apply' : 'super.catalog.imports.dry-run', { job: job.public_id }), { force }, { preserveScroll: true });
  };

  const discard = (): void => {
    if (window.confirm(t('super.catalog.imports.discard_confirm'))) router.delete(route('super.catalog.imports.destroy', { job: job.public_id }));
  };

  const countRows: CountRow[] = report ? Object.entries(report.row_counts).map(([table, c]) => ({ table, ...c })) : [];
  const countColumns: SuperColumn<CountRow>[] = [
    { key: 'table', label: t('super.catalog.imports.report.table'), render: (r) => <Box sx={{ fontFamily: 'monospace' }}>{r.table}</Box> },
    { key: 'rows', label: t('super.catalog.imports.report.rows'), align: 'right', render: (r) => n(r.rows) },
    { key: 'inserted', label: t('super.catalog.imports.report.inserted'), align: 'right', render: (r) => <Typography variant="body2" color={r.inserted > 0 ? 'success.main' : 'text.secondary'}>{n(r.inserted)}</Typography> },
    { key: 'updated', label: t('super.catalog.imports.report.updated'), align: 'right', render: (r) => <Typography variant="body2" color={r.updated > 0 ? 'info.main' : 'text.secondary'}>{n(r.updated)}</Typography> },
    { key: 'deactivated', label: t('super.catalog.imports.report.deactivated'), align: 'right', render: (r) => <Typography variant="body2" color={r.deactivated > 0 ? 'warning.main' : 'text.secondary'}>{n(r.deactivated)}</Typography> },
  ];

  const samples: SampleRow[] = (report?.issue_samples ?? []).map((s, i) => ({ ...s, key: `${i}` }));
  const sampleColumns: SuperColumn<SampleRow>[] = [
    { key: 'kind', label: t('super.catalog.issues.column.kind'), render: (s) => <Chip size="small" color="warning" variant="outlined" label={t(`super.catalog.issues.kind.${s.kind}`, { defaultValue: s.kind })} /> },
    { key: 'row', label: t('super.catalog.issues.column.row'), align: 'right', render: (s) => n(s.source_row) },
    { key: 'what', label: t('super.catalog.issues.column.what'), bn: true, render: (s) => describe(s.payload) },
  ];

  const issueColumns: SuperColumn<CatalogImportIssueRow>[] = [
    { key: 'kind', label: t('super.catalog.issues.column.kind'), render: (i) => <Chip size="small" color={i.resolved_at ? 'default' : 'warning'} variant="outlined" label={t(`super.catalog.issues.kind.${i.kind}`, { defaultValue: i.kind })} /> },
    { key: 'row', label: t('super.catalog.issues.column.row'), align: 'right', render: (i) => n(i.source_row) },
    { key: 'what', label: t('super.catalog.issues.column.what'), bn: true, render: (i) => describe(i.payload) },
    { key: 'resolved', label: t('super.catalog.issues.column.resolved'), render: (i) => (i.resolved_at ? formatDhaka(i.resolved_at, 'D MMM YYYY', locale) : t('super.catalog.issues.open')) },
  ];

  const canApply = job.status !== 'queued' && job.status !== 'running' && job.has_bundle && !(job.mode === 'apply' && job.status === 'succeeded' && report?.status === 'applied');
  // A release with this checksum exists (the server says so, or the importer answered "already imported"): every
  // run from here carries `force`, and the warning names the release.
  const knownVersion = known_version ?? (job.status === 'succeeded' && report?.status === 'already_imported' ? report.version : null);
  const alreadyImported = knownVersion !== null;

  return (
    <Box>
      <SuperNav />

      <Stack spacing={2}>
        <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap' }} useFlexGap>
          <Button size="small" component={RouterLink} href={route('super.catalog.imports.index')}>{t('super.catalog.imports.back')}</Button>
          <Typography variant="h6" component="h2" sx={{ flexGrow: 1 }}>{job.version ?? t('super.catalog.imports.version_auto')}</Typography>
          <JobStatusChip job={job} size="medium" />
        </Stack>

        <Card variant="outlined">
          <CardContent>
            <Stack direction="row" spacing={3} useFlexGap sx={{ flexWrap: 'wrap' }}>
              <Box><Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('super.catalog.imports.files')}</Typography><Typography variant="body2">{job.bundle_files.join(', ')}</Typography></Box>
              <Box><Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('super.catalog.imports.source')}</Typography><Typography variant="body2">{job.source ? t(`super.catalog.imports.sources.${job.source}`) : '—'}</Typography></Box>
              <Box><Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('super.catalog.imports.mode')}</Typography><Typography variant="body2">{job.full ? t('super.catalog.imports.mode_full') : t('super.catalog.imports.mode_incremental')}</Typography></Box>
              <Box><Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('super.catalog.imports.release_ref')}</Typography><Typography variant="body2">{job.release_ref ?? '—'}</Typography></Box>
              <Box><Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('super.catalog.imports.checksum')}</Typography><Typography variant="body2" sx={{ fontFamily: 'monospace', fontSize: 12 }}>{job.checksum?.slice(0, 16) ?? '—'}…</Typography></Box>
              <Box><Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('super.catalog.jobs.column.by')}</Typography><Typography variant="body2">{job.requested_by ?? '—'}</Typography></Box>
            </Stack>
            {job.progress.rows ? (
              <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>
                {Object.entries(job.progress.rows).map(([file, count]) => `${file} ${n(count)}`).join(' · ')}
              </Typography>
            ) : null}

            {unfinished ? (
              <Box sx={{ mt: 2 }}>
                <LinearProgress variant={job.progress.percent === undefined ? 'indeterminate' : 'determinate'} value={job.progress.percent ?? 0} />
                <Typography variant="caption" color="text.secondary">{job.progress.step ? t(`super.catalog.imports.steps.${job.progress.step}`, { defaultValue: job.progress.step }) : t('super.catalog.imports.waiting')}</Typography>
              </Box>
            ) : null}

            {job.status === 'failed' ? <Alert severity="error" sx={{ mt: 2 }}>{job.error}</Alert> : null}
            {alreadyImported ? <Alert severity="warning" sx={{ mt: 2 }}>{t('super.catalog.imports.already_imported', { version: knownVersion ?? '' })}</Alert> : null}
            {report?.index_error ? <Alert severity="warning" sx={{ mt: 2 }}>{t('super.catalog.imports.index_error', { error: report.index_error })}</Alert> : null}

            <Stack direction="row" spacing={1} sx={{ mt: 2, flexWrap: 'wrap' }} useFlexGap>
              <Button variant="outlined" disabled={!canApply} onClick={() => queue('dry-run', alreadyImported)}>{t('super.catalog.imports.dry_run')}</Button>
              <Button variant="contained" disabled={!canApply} onClick={() => { if (window.confirm(t('super.catalog.imports.apply_confirm'))) queue('apply', alreadyImported); }}>{t('super.catalog.imports.apply')}</Button>
              {job.has_bundle && !unfinished ? <Button color="error" onClick={discard}>{t('super.catalog.imports.discard')}</Button> : null}
            </Stack>
          </CardContent>
        </Card>

        {report && report.status !== 'already_imported' ? (
          <Card variant="outlined">
            <CardContent sx={{ pb: 0 }}>
              <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap' }} useFlexGap>
                <Typography variant="subtitle1" component="h3">{t(report.status === 'dry_run' ? 'super.catalog.imports.report.dry_run_title' : 'super.catalog.imports.report.applied_title')}</Typography>
                <Chip size="small" variant="outlined" label={t('super.catalog.imports.report.changes', { count: n(report.total_changes) })} />
                <Chip size="small" variant="outlined" label={t('super.catalog.imports.report.duration', { ms: n(report.duration_ms) })} />
                {report.indexed !== undefined ? <Chip size="small" variant="outlined" color={report.indexed ? 'success' : 'default'} label={t(report.indexed ? 'super.catalog.imports.report.indexed' : 'super.catalog.imports.report.not_indexed')} /> : null}
              </Stack>
              <Typography variant="caption" color="text.secondary">{t(report.status === 'dry_run' ? 'super.catalog.imports.report.dry_run_help' : 'super.catalog.imports.report.applied_help', { version: report.version })}</Typography>
            </CardContent>
            <SuperTable columns={countColumns} rows={countRows} rowKey={(r) => r.table} empty={t('super.catalog.imports.report.no_rows')} label={t('super.catalog.imports.report.table')} />
            <CardContent>
              <Typography variant="subtitle2" gutterBottom>
                {t('super.catalog.imports.report.issues_title', { count: n(Object.values(report.issues).reduce((a, b) => a + b, 0)) })}
              </Typography>
              <Stack direction="row" spacing={0.5} useFlexGap sx={{ flexWrap: 'wrap', mb: 1 }}>
                {Object.entries(report.issues).map(([kind, count]) => <Chip key={kind} size="small" color="warning" variant="outlined" label={`${t(`super.catalog.issues.kind.${kind}`, { defaultValue: kind })} · ${n(count)}`} />)}
                {Object.keys(report.issues).length === 0 ? <Typography variant="body2" color="text.secondary">{t('super.catalog.imports.report.no_issues')}</Typography> : null}
              </Stack>
            </CardContent>
            {samples.length > 0 && issues.length === 0 ? <SuperTable columns={sampleColumns} rows={samples} rowKey={(s) => s.key} empty="" label={t('super.catalog.issues.column.what')} /> : null}
          </Card>
        ) : null}

        {version ? (
          <Card variant="outlined">
            <CardContent>
              <Typography variant="subtitle1" component="h3">{t('super.catalog.imports.version_created', { version: version.version })}</Typography>
              <Typography variant="body2" color="text.secondary">
                {t(`super.catalog.versions.status.${version.status}`, { defaultValue: version.status })} · {version.applied_at ? formatDhaka(version.applied_at, 'D MMM YYYY, h:mm a', locale) : '—'} · {version.applied_by ?? '—'}
              </Typography>
            </CardContent>
            {issues.length > 0 ? <SuperTable columns={issueColumns} rows={issues} rowKey={(i) => String(i.id)} empty="" label={t('super.catalog.issues.column.what')} /> : null}
          </Card>
        ) : null}
      </Stack>
    </Box>
  );
}

Show.layout = (page: ReactNode) => <PanelLayout title="super.catalog.imports.title">{page}</PanelLayout>;
