// One reconciliation report (SCHEMA §2.12): what each referenced catalogue id resolves to today, the sample of
// tenant rows behind it (with their snapshotted names — the proof that snapshotting works), and the two actions:
// mark reviewed, or tell the clinic's owner by mail.
import { useState, type FormEvent, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { SuperNav } from '@panel/Components/Super/SuperNav';
import { SuperTable, type SuperColumn } from '@panel/Components/Super/SuperTable';
import type { ReconciliationReference, ReconciliationRow } from '@panel/Components/Super/types';
import { formatNumber } from '@shared/format/number';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { hasRoute, route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type Report = ReconciliationRow & { resolved_by: string | null; catalog_version_id: number | null; notified_at: string | null };

type SampleRow = { id: number; ref: string | number | null } & Record<string, unknown>;

type Props = PageProps<{
  report: Report;
  references: Record<string, ReconciliationReference[]>;
  sample: SampleRow[];
}>;

export default function ReconciliationShow({ report, references, sample }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);
  const n = (v: number): string => formatNumber(v, locale);

  const resolve = (): void => {
    router.post(route('super.catalog.reconciliation.resolve', { report: report.id }), {}, { preserveScroll: true });
  };

  const notify = (e: FormEvent): void => {
    e.preventDefault();
    setBusy(true);
    router.post(route('super.catalog.reconciliation.notify', { report: report.id }), { note }, { preserveScroll: true, onFinish: () => setBusy(false) });
  };

  const refColumns: SuperColumn<ReconciliationReference & { kind: string }>[] = [
    { key: 'kind', label: t('super.reconciliation.detail_page.kind'), render: (r) => <Chip size="small" variant="outlined" color={r.kind === 'orphan' ? 'error' : r.kind === 'inactive' ? 'warning' : 'default'} label={t(`super.reconciliation.kind.${r.kind}`, { defaultValue: r.kind })} /> },
    { key: 'ref', label: t('super.reconciliation.detail_page.ref'), render: (r) => <Box sx={{ fontFamily: 'monospace' }}>{r.ref}</Box> },
    { key: 'name', label: t('super.reconciliation.detail_page.catalog_now'), bn: true, render: (r) => (r.exists ? `${r.name ?? '—'}${r.is_active === false ? ` · ${t('super.catalog.drawer.inactive')}` : ''}` : <Typography variant="body2" color="error.main">{t('super.reconciliation.detail_page.gone')}</Typography>) },
    { key: 'rows', label: t('super.reconciliation.detail_page.rows'), align: 'right', render: (r) => n(r.rows) },
  ];
  const refRows = Object.entries(references).flatMap(([kind, list]) => list.map((r) => ({ ...r, kind })));

  const sampleKeys = Array.from(new Set(sample.flatMap((row) => Object.keys(row)))).filter((k) => k !== 'id' && k !== 'ref');
  const sampleColumns: SuperColumn<SampleRow>[] = [
    { key: 'id', label: 'id', align: 'right', render: (r) => n(r.id) },
    { key: 'ref', label: report.column_name, render: (r) => <Box sx={{ fontFamily: 'monospace' }}>{r.ref === null ? '—' : String(r.ref)}</Box> },
    ...sampleKeys.map((k): SuperColumn<SampleRow> => ({ key: k, label: k, bn: true, render: (r) => (r[k] === null || r[k] === undefined ? '—' : String(r[k])) })),
  ];

  return (
    <Box>
      <SuperNav />

      <Stack spacing={2}>
        <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap' }} useFlexGap>
          <Button size="small" component={RouterLink} href={route('super.catalog.reconciliation.index')}>{t('super.reconciliation.back')}</Button>
          <Typography variant="h6" component="h2" sx={{ flexGrow: 1, fontFamily: 'monospace' }}>{report.table_name}.{report.column_name}</Typography>
          <Chip size="small" variant="outlined" color={report.status === 'clean' ? 'success' : report.orphan_count > 0 ? 'error' : 'warning'} label={t(`super.reconciliation.status.${report.status}`, { defaultValue: report.status })} />
          {report.resolved_at !== null ? <Chip size="small" color="success" label={t('super.reconciliation.reviewed')} /> : null}
        </Stack>

        <Card variant="outlined">
          <CardContent>
            <Stack direction="row" spacing={3} useFlexGap sx={{ flexWrap: 'wrap' }}>
              <Box>
                <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('super.reconciliation.column.clinic')}</Typography>
                {report.tenant === null ? '—' : hasRoute('super.tenants.show') ? <Box component={RouterLink} href={route('super.tenants.show', { tenant: report.tenant.public_id })} sx={{ color: 'primary.main', fontWeight: 600 }} lang="bn">{report.tenant.name}</Box> : <Typography variant="body2" lang="bn">{report.tenant.name}</Typography>}
              </Box>
              <Box><Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('super.reconciliation.run')}</Typography><Typography variant="body2" sx={{ fontFamily: 'monospace', fontSize: 12 }}>{report.run_id}</Typography></Box>
              <Box><Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('super.reconciliation.column.checked')}</Typography><Typography variant="body2">{n(report.checked_count)}</Typography></Box>
              <Box><Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('super.reconciliation.column.orphans')}</Typography><Typography variant="body2" color={report.orphan_count > 0 ? 'error.main' : 'text.primary'}>{n(report.orphan_count)}</Typography></Box>
              <Box><Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('super.reconciliation.detail_page.scanned_at')}</Typography><Typography variant="body2">{report.created_at ? formatDhaka(report.created_at, 'D MMM YYYY, h:mm a', locale) : '—'}</Typography></Box>
              <Box><Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('super.reconciliation.reviewed')}</Typography><Typography variant="body2">{report.resolved_at ? `${formatDhaka(report.resolved_at, 'D MMM YYYY, h:mm a', locale)}${report.resolved_by ? ` · ${report.resolved_by}` : ''}` : '—'}</Typography></Box>
              <Box><Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('super.reconciliation.detail_page.notified')}</Typography><Typography variant="body2">{report.notified_at ? formatDhaka(report.notified_at, 'D MMM YYYY, h:mm a', locale) : t('super.reconciliation.detail_page.never')}</Typography></Box>
            </Stack>
          </CardContent>
        </Card>

        <Card variant="outlined">
          <CardContent sx={{ pb: 0 }}>
            <Typography variant="subtitle1" component="h3">{t('super.reconciliation.detail_page.references')}</Typography>
            <Typography variant="caption" color="text.secondary">{t('super.reconciliation.detail_page.references_help')}</Typography>
          </CardContent>
          <SuperTable columns={refColumns} rows={refRows} rowKey={(r) => `${r.kind}:${r.ref}`} empty={t('super.reconciliation.detail_page.no_references')} label={t('super.reconciliation.detail_page.references')} />
        </Card>

        <Card variant="outlined">
          <CardContent sx={{ pb: 0 }}>
            <Typography variant="subtitle1" component="h3">{t('super.reconciliation.detail_page.sample')}</Typography>
            <Typography variant="caption" color="text.secondary">{t('super.reconciliation.detail_page.sample_help')}</Typography>
          </CardContent>
          <SuperTable columns={sampleColumns} rows={sample} rowKey={(r) => String(r.id)} empty={t('super.reconciliation.detail_page.no_sample')} label={t('super.reconciliation.detail_page.sample')} />
        </Card>

        <Card variant="outlined">
          <CardContent>
            <Typography variant="subtitle1" component="h3" gutterBottom>{t('super.reconciliation.detail_page.actions')}</Typography>
            {report.tenant === null ? <Alert severity="info">{t('super.reconciliation.no_tenant')}</Alert> : (
              <Box component="form" onSubmit={notify} noValidate sx={{ display: 'grid', gap: 1.5, maxWidth: 640 }}>
                <Typography variant="body2" color="text.secondary">{t('super.reconciliation.detail_page.notify_help')}</Typography>
                <TextField size="small" multiline minRows={2} label={t('super.reconciliation.detail_page.note')} value={note} onChange={(e) => setNote(e.target.value)} slotProps={{ htmlInput: { maxLength: 1000 } }} />
                <Stack direction="row" spacing={1}>
                  <Button type="submit" variant="contained" disabled={busy}>{t('super.reconciliation.detail_page.notify')}</Button>
                  <Button variant="outlined" onClick={resolve} disabled={report.resolved_at !== null}>{t('super.reconciliation.mark_reviewed')}</Button>
                </Stack>
              </Box>
            )}
          </CardContent>
        </Card>
      </Stack>
    </Box>
  );
}

ReconciliationShow.layout = (page: ReactNode) => <PanelLayout title="super.reconciliation.title">{page}</PanelLayout>;
