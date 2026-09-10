// Catalogue imports (CATALOG.md §5) from the console: upload a bundle in the exact format `catalog:import` reads,
// then dry-run / apply it as Horizon jobs from its own page. This screen is also the release history (who
// applied what and when), the open import issues, and the two maintenance buttons — rebuild the search index,
// run the reconciliation sweep — each a queued job with its last status shown next to it.
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { router, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import FormControlLabel from '@mui/material/FormControlLabel';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { SuperTable, type SuperColumn } from '@panel/Components/Super/SuperTable';
import { JobStatusChip } from '@panel/Components/Super/Catalog/JobStatusChip';
import type { CatalogJobRow, CatalogVersionRow, SearchIndexStatus } from '@panel/Components/Super/types';
import { formatNumber } from '@shared/format/number';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type { Locale } from '@shared/types/shared-props';

type Props = PageProps<{
  versions: CatalogVersionRow[];
  jobs: CatalogJobRow[];
  reindex: CatalogJobRow | null;
  reconcile: CatalogJobRow | null;
  search: SearchIndexStatus;
  open_issues: { open: number; by_kind: Record<string, number> };
  sources: string[];
  busy: boolean;
}>;

interface UploadForm {
  files: File[];
  source: string;
  version: string;
  release_ref: string;
  full: boolean;
  [key: string]: File[] | string | boolean;
}

const POLL_MS = 3000;

function when(iso: string | null, locale: Locale): string {
  return iso === null ? '—' : formatDhaka(iso, 'D MMM YYYY, h:mm a', locale);
}

export default function Index({ versions, jobs, reindex, reconcile, search, open_issues, sources, busy }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const n = (v: number): string => formatNumber(v, locale);
  const form = useForm<UploadForm>({ files: [], source: 'dgda', version: '', release_ref: '', full: false });
  const [fileNames, setFileNames] = useState<string[]>([]);

  // While a job is queued or running, keep the page honest without a websocket: reload the job props on a timer.
  useEffect(() => {
    if (!busy) return;
    const timer = window.setInterval(() => router.reload({ only: ['jobs', 'reindex', 'reconcile', 'busy', 'versions', 'search', 'open_issues'] }), POLL_MS);
    return () => window.clearInterval(timer);
  }, [busy]);

  const upload = (e: FormEvent): void => {
    e.preventDefault();
    form.post(route('super.catalog.imports.store'), { forceFormData: true, onSuccess: () => { form.reset(); setFileNames([]); } });
  };

  const queue = (name: 'super.catalog.index.rebuild' | 'super.catalog.reconcile.run'): void => {
    router.post(route(name), {}, { preserveScroll: true });
  };

  const jobColumns: SuperColumn<CatalogJobRow>[] = [
    { key: 'kind', label: t('super.catalog.jobs.column.kind'), render: (j) => <Typography variant="body2" sx={{ fontWeight: 600 }}>{t(`super.catalog.jobs.kind.${j.kind}`)}{j.mode ? ` · ${t(`super.catalog.jobs.mode.${j.mode}`)}` : ''}</Typography> },
    { key: 'what', label: t('super.catalog.jobs.column.what'), render: (j) => (j.kind === 'import' ? <Box><Typography variant="body2">{j.version ?? t('super.catalog.imports.version_auto')}</Typography><Typography variant="caption" color="text.secondary">{j.bundle_files.join(', ')}</Typography></Box> : (j.report?.run_id ?? j.progress.run_id ?? '—')) },
    { key: 'status', label: t('super.catalog.jobs.column.status'), render: (j) => <JobStatusChip job={j} /> },
    { key: 'by', label: t('super.catalog.jobs.column.by'), render: (j) => j.requested_by ?? '—' },
    { key: 'at', label: t('super.catalog.jobs.column.at'), render: (j) => when(j.finished_at ?? j.started_at ?? j.created_at, locale) },
    { key: 'open', label: '', align: 'right', render: (j) => (j.kind === 'import' ? <Button size="small" component={RouterLink} href={route('super.catalog.imports.show', { job: j.public_id })}>{t('super.catalog.imports.open')}</Button> : (j.error ? <Typography variant="caption" color="error.main">{j.error}</Typography> : null)) },
  ];

  const versionColumns: SuperColumn<CatalogVersionRow>[] = [
    { key: 'version', label: t('super.catalog.versions.column.version'), render: (v) => (<Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}><Typography variant="body2" sx={{ fontWeight: 600, fontFamily: 'monospace' }}>{v.version}</Typography>{v.is_current ? <Chip size="small" color="success" label={t('super.catalog.versions.current')} /> : null}</Stack>) },
    { key: 'status', label: t('super.catalog.versions.column.status'), render: (v) => <Chip size="small" variant="outlined" label={t(`super.catalog.versions.status.${v.status}`, { defaultValue: v.status })} /> },
    { key: 'applied', label: t('super.catalog.versions.column.applied'), render: (v) => (<Box><Typography variant="body2">{when(v.applied_at, locale)}</Typography><Typography variant="caption" color="text.secondary">{v.applied_by ?? '—'}</Typography></Box>) },
    { key: 'ref', label: t('super.catalog.versions.column.ref'), render: (v) => v.dgda_release_ref ?? '—' },
    { key: 'changes', label: t('super.catalog.versions.column.changes'), align: 'right', render: (v) => n(Object.values(v.row_counts).reduce((sum, c) => sum + (c.inserted ?? 0) + (c.updated ?? 0) + (c.deactivated ?? 0), 0)) },
    { key: 'issues', label: t('super.catalog.versions.column.issues'), align: 'right', render: (v) => (v.issues_total === 0 ? '—' : <Typography variant="body2" color={v.issues_open > 0 ? 'warning.main' : 'text.secondary'}>{n(v.issues_open)} / {n(v.issues_total)}</Typography>) },
    { key: 'notes', label: t('super.catalog.versions.column.notes'), render: (v) => <Typography variant="caption" color="text.secondary">{v.notes ?? ''}</Typography> },
  ];

  const indexSummary = Object.entries(search.indexes).map(([uid, count]) => `${uid}: ${count === null ? t('super.catalog.maintenance.index_missing') : n(count)}`).join(' · ');

  return (
    <Box>
      <Stack spacing={2}>
        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' }, alignItems: 'start' }}>
          <Card variant="outlined">
            <CardContent>
              <Typography variant="subtitle1" component="h2" gutterBottom>{t('super.catalog.imports.upload_title')}</Typography>
              <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>{t('super.catalog.imports.upload_help')}</Typography>
              <Box component="form" onSubmit={upload} noValidate sx={{ display: 'grid', gap: 1.5 }}>
                <Button variant="outlined" component="label" size="small" sx={{ justifySelf: 'start' }}>
                  {t('super.catalog.imports.choose_files')}
                  <input
                    type="file"
                    name="files[]"
                    hidden
                    multiple
                    accept=".csv,.zip,text/csv,application/zip"
                    data-testid="bundle-files"
                    onChange={(e) => { const list = Array.from(e.target.files ?? []); form.setData('files', list); setFileNames(list.map((f) => f.name)); }}
                  />
                </Button>
                {fileNames.length > 0 ? <Typography variant="caption" color="text.secondary">{fileNames.join(', ')}</Typography> : null}
                {form.errors.files || (form.errors as Record<string, string | undefined>)['files.0'] ? <Alert severity="error">{form.errors.files ?? (form.errors as Record<string, string | undefined>)['files.0']}</Alert> : null}
                <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1}>
                  <TextField select size="small" label={t('super.catalog.imports.source')} value={form.data.source} onChange={(e) => form.setData('source', e.target.value)} sx={{ minWidth: 140 }}>
                    {sources.map((s) => <MenuItem key={s} value={s}>{t(`super.catalog.imports.sources.${s}`)}</MenuItem>)}
                  </TextField>
                  <TextField size="small" label={t('super.catalog.imports.version')} placeholder="2026.09.1" value={form.data.version} onChange={(e) => form.setData('version', e.target.value)} error={Boolean(form.errors.version)} helperText={form.errors.version ?? t('super.catalog.imports.version_help')} />
                  <TextField size="small" label={t('super.catalog.imports.release_ref')} value={form.data.release_ref} onChange={(e) => form.setData('release_ref', e.target.value)} />
                </Stack>
                <FormControlLabel control={<Switch checked={form.data.full} onChange={(e) => form.setData('full', e.target.checked)} />} label={t('super.catalog.imports.full')} />
                <Typography variant="caption" color="text.secondary">{t('super.catalog.imports.full_help')}</Typography>
                {(form.errors as Record<string, string | undefined>).domain ? <Alert severity="error">{(form.errors as Record<string, string | undefined>).domain}</Alert> : null}
                <Box>
                  <Button type="submit" variant="contained" disabled={form.processing || form.data.files.length === 0}>{t('super.catalog.imports.upload')}</Button>
                </Box>
              </Box>
            </CardContent>
          </Card>

          <Stack spacing={2}>
            <Card variant="outlined">
              <CardContent>
                <Typography variant="subtitle1" component="h2" gutterBottom>{t('super.catalog.maintenance.index_title')}</Typography>
                <Typography variant="body2" color="text.secondary">
                  {search.driver !== 'meilisearch' ? t('super.catalog.maintenance.index_not_driver') : search.reachable ? indexSummary : t('super.catalog.maintenance.index_unreachable')}
                </Typography>
                <Stack direction="row" spacing={1} sx={{ alignItems: 'center', mt: 1.5, flexWrap: 'wrap' }} useFlexGap>
                  <Button variant="outlined" size="small" onClick={() => queue('super.catalog.index.rebuild')} disabled={busy || search.driver !== 'meilisearch'}>{t('super.catalog.maintenance.rebuild')}</Button>
                  {reindex ? <JobStatusChip job={reindex} /> : null}
                  {reindex ? <Typography variant="caption" color="text.secondary">{when(reindex.finished_at ?? reindex.started_at ?? reindex.created_at, locale)}{reindex.requested_by ? ` · ${reindex.requested_by}` : ''}</Typography> : null}
                </Stack>
                {reindex?.error ? <Alert severity="error" sx={{ mt: 1 }}>{reindex.error}</Alert> : null}
              </CardContent>
            </Card>
            <Card variant="outlined">
              <CardContent>
                <Typography variant="subtitle1" component="h2" gutterBottom>{t('super.catalog.maintenance.reconcile_title')}</Typography>
                <Typography variant="body2" color="text.secondary">{t('super.catalog.maintenance.reconcile_help')}</Typography>
                <Stack direction="row" spacing={1} sx={{ alignItems: 'center', mt: 1.5, flexWrap: 'wrap' }} useFlexGap>
                  <Button variant="outlined" size="small" onClick={() => queue('super.catalog.reconcile.run')} disabled={busy}>{t('super.catalog.maintenance.reconcile')}</Button>
                  {reconcile ? <JobStatusChip job={reconcile} /> : null}
                  {reconcile?.report?.run_id ? (
                    <Button size="small" component={RouterLink} href={route('super.catalog.reconciliation.index', { run: reconcile.report.run_id, unresolved: 0 })}>
                      {t('super.catalog.maintenance.reconcile_view', { count: n(reconcile.report.tenants ?? 0) })}
                    </Button>
                  ) : null}
                </Stack>
                {reconcile?.error ? <Alert severity="error" sx={{ mt: 1 }}>{reconcile.error}</Alert> : null}
              </CardContent>
            </Card>
            {open_issues.open > 0 ? (
              <Alert severity="warning">
                {t('super.catalog.imports.open_issues', { count: n(open_issues.open) })} {Object.entries(open_issues.by_kind).map(([k, c]) => `${t(`super.catalog.issues.kind.${k}`, { defaultValue: k })} ${n(c)}`).join(' · ')}
              </Alert>
            ) : null}
          </Stack>
        </Box>

        <Card variant="outlined">
          <CardContent sx={{ pb: 0 }}>
            <Typography variant="subtitle1" component="h2">{t('super.catalog.jobs.title')}</Typography>
          </CardContent>
          <SuperTable columns={jobColumns} rows={jobs} rowKey={(j) => j.public_id} empty={t('super.catalog.jobs.empty')} label={t('super.catalog.jobs.title')} />
        </Card>

        <Card variant="outlined">
          <CardContent sx={{ pb: 0 }}>
            <Typography variant="subtitle1" component="h2">{t('super.catalog.versions.title')}</Typography>
            <Typography variant="caption" color="text.secondary">{t('super.catalog.versions.subtitle')}</Typography>
          </CardContent>
          <SuperTable columns={versionColumns} rows={versions} rowKey={(v) => String(v.id)} empty={t('super.catalog.versions.empty')} label={t('super.catalog.versions.title')} />
        </Card>
      </Stack>
    </Box>
  );
}

Index.layout = (page: ReactNode) => <PanelLayout title="super.catalog.imports.title">{page}</PanelLayout>;
