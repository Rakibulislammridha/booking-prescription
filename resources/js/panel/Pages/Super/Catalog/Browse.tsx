// The shared clinical catalogue, browsed from the console (BRIEF §3.2): six tabs, server-side search (the same
// Meilisearch index doctors type into, Postgres when it is not there), an active filter, pagination, and a
// read-only drawer with the three edits that are safe centrally. The URL always describes what is on screen.
import { useEffect, useRef, useState, type ReactNode, type SyntheticEvent } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import InputAdornment from '@mui/material/InputAdornment';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import ChevronLeftIcon from '@mui/icons-material/ChevronLeft';
import ChevronRightIcon from '@mui/icons-material/ChevronRight';
import SearchIcon from '@mui/icons-material/Search';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { SuperTable, type SuperColumn } from '@panel/Components/Super/SuperTable';
import { DetailDrawer } from '@panel/Components/Super/Catalog/DetailDrawer';
import type {
  CatalogAllergyClassRow, CatalogBrandRow, CatalogGenericRow, CatalogIcd10Row, CatalogInteractionRow, CatalogRow, CatalogStrengthRow, CatalogTab, ConsoleMeta,
} from '@panel/Components/Super/types';
import { formatNumber } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{
  tab: CatalogTab;
  tabs: CatalogTab[];
  counts: Record<CatalogTab, number>;
  filters: { q: string; active: 'all' | 'active' | 'inactive' };
  rows: CatalogRow[];
  meta: ConsoleMeta & { per_page: number };
  engine: 'meilisearch' | 'database';
}>;

const SEARCH_DEBOUNCE_MS = 300;

function ActiveCell({ active }: { active: boolean }) {
  const { t } = useTranslation();
  return <Chip size="small" variant="outlined" color={active ? 'success' : 'default'} label={active ? t('super.catalog.drawer.active') : t('super.catalog.drawer.inactive')} />;
}

export default function Browse({ tab, tabs, counts, filters, rows, meta, engine }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [q, setQ] = useState(filters.q);
  const [active, setActive] = useState(filters.active);
  const [selected, setSelected] = useState<number | null>(null);
  const typed = useRef(false);
  const n = (v: number | null | undefined): string => (v === null || v === undefined ? '—' : formatNumber(v, locale));

  const go = (params: { tab?: CatalogTab; q?: string; active?: string; page?: number }): void => {
    const query: Record<string, string | number> = { tab: params.tab ?? tab };
    const nextQ = params.q ?? q;
    const nextActive = params.active ?? active;
    if (nextQ !== '') query.q = nextQ;
    if (nextActive !== 'all') query.active = nextActive;
    if (params.page !== undefined && params.page > 1) query.page = params.page;
    router.get(route('super.catalog.index'), query, { preserveState: true, replace: true, preserveScroll: true });
  };

  useEffect(() => {
    if (!typed.current) return;
    const timer = window.setTimeout(() => go({ q, page: 1 }), SEARCH_DEBOUNCE_MS);
    return () => window.clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [q]);

  const changeTab = (_e: SyntheticEvent, next: CatalogTab): void => {
    setSelected(null);
    go({ tab: next, page: 1 });
  };

  const status: SuperColumn<CatalogRow> = { key: 'status', label: t('super.catalog.columns.status'), render: (row) => <ActiveCell active={row.is_active} /> };

  const columns = ((): SuperColumn<CatalogRow>[] => {
    switch (tab) {
      case 'generics':
        return [
          { key: 'name', label: t('super.catalog.columns.generic'), bn: true, render: (r) => { const g = r as CatalogGenericRow; return (<Box><Typography variant="body2" sx={{ fontWeight: 600 }}>{g.name}{g.needs_review ? <Chip size="small" color="warning" sx={{ ml: 1 }} label={t('super.catalog.drawer.needs_review')} /> : null}</Typography><Typography variant="caption" color="text.secondary" lang="bn">{g.name_bn ?? g.slug}</Typography></Box>); } },
          { key: 'class', label: t('super.catalog.columns.class'), render: (r) => (r as CatalogGenericRow).therapeutic_class ?? '—' },
          { key: 'atc', label: t('super.catalog.columns.atc'), render: (r) => (r as CatalogGenericRow).atc_code ?? '—' },
          { key: 'aliases', label: t('super.catalog.columns.aliases'), bn: true, render: (r) => (r as CatalogGenericRow).aliases.join(', ') || '—' },
          { key: 'brands', label: t('super.catalog.columns.brands'), align: 'right', render: (r) => n((r as CatalogGenericRow).brands_count) },
          { key: 'info', label: t('super.catalog.columns.information'), render: (r) => { const g = r as CatalogGenericRow; return g.info_slug ? <Chip size="small" variant="outlined" color={g.info_published ? 'success' : 'default'} label={g.info_published ? t('super.catalog.columns.published') : t('super.catalog.columns.draft')} /> : '—'; } },
          status,
        ];
      case 'brands':
        return [
          { key: 'name', label: t('super.catalog.columns.brand'), bn: true, render: (r) => { const b = r as CatalogBrandRow; return (<Box><Typography variant="body2" sx={{ fontWeight: 600 }}>{b.name}</Typography><Typography variant="caption" color="text.secondary">{b.slug}</Typography></Box>); } },
          { key: 'generic', label: t('super.catalog.columns.generic'), render: (r) => (r as CatalogBrandRow).generic.name ?? '—' },
          { key: 'manufacturer', label: t('super.catalog.columns.manufacturer'), render: (r) => (r as CatalogBrandRow).manufacturer ?? '—' },
          { key: 'dar', label: t('super.catalog.columns.dar'), render: (r) => (r as CatalogBrandRow).dar_number ?? '—' },
          { key: 'popularity', label: t('super.catalog.columns.popularity'), align: 'right', render: (r) => n((r as CatalogBrandRow).popularity) },
          { key: 'strengths', label: t('super.catalog.columns.strengths'), align: 'right', render: (r) => n((r as CatalogBrandRow).strengths_count) },
          status,
        ];
      case 'strengths':
        return [
          { key: 'brand', label: t('super.catalog.columns.brand'), render: (r) => { const s = r as CatalogStrengthRow; return (<Box><Typography variant="body2" sx={{ fontWeight: 600 }}>{s.brand.name}</Typography><Typography variant="caption" color="text.secondary">{s.generic.name}</Typography></Box>); } },
          { key: 'strength', label: t('super.catalog.columns.strength'), render: (r) => (r as CatalogStrengthRow).strength_label },
          { key: 'form', label: t('super.catalog.columns.form'), render: (r) => (r as CatalogStrengthRow).form.name },
          { key: 'route', label: t('super.catalog.columns.route'), render: (r) => (r as CatalogStrengthRow).route?.name ?? '—' },
          { key: 'pack', label: t('super.catalog.columns.pack'), render: (r) => (r as CatalogStrengthRow).pack_size ?? '—' },
          { key: 'mg', label: 'mg', align: 'right', render: (r) => n((r as CatalogStrengthRow).strength_mg) },
          status,
        ];
      case 'icd10':
        return [
          { key: 'code', label: t('super.catalog.columns.code'), render: (r) => <Box sx={{ fontFamily: 'monospace', fontWeight: 600 }}>{(r as CatalogIcd10Row).code}</Box> },
          { key: 'title', label: t('super.catalog.columns.title'), bn: true, render: (r) => { const i = r as CatalogIcd10Row; return (<Box><Typography variant="body2">{i.title}</Typography><Typography variant="caption" color="text.secondary" lang="bn">{i.title_bn ?? ''}</Typography></Box>); } },
          { key: 'block', label: t('super.catalog.columns.block'), render: (r) => (r as CatalogIcd10Row).block ?? '—' },
          { key: 'aliases', label: t('super.catalog.columns.aliases'), bn: true, render: (r) => (r as CatalogIcd10Row).aliases.join(', ') || '—' },
          { key: 'billable', label: t('super.catalog.columns.billable'), render: (r) => ((r as CatalogIcd10Row).is_billable ? t('super.settings.bool_on') : t('super.settings.bool_off')) },
          status,
        ];
      case 'interactions':
        return [
          { key: 'pair', label: t('super.catalog.columns.pair'), render: (r) => { const x = r as CatalogInteractionRow; return <Typography variant="body2" sx={{ fontWeight: 600 }}>{x.generic_a.name} + {x.generic_b.name}</Typography>; } },
          { key: 'severity', label: t('super.catalog.columns.severity'), render: (r) => { const x = r as CatalogInteractionRow; return <Chip size="small" color={x.severity === 'contraindicated' || x.severity === 'major' ? 'error' : x.severity === 'moderate' ? 'warning' : 'default'} variant="outlined" label={t(`super.catalog.severity.${x.severity}`, { defaultValue: x.severity })} />; } },
          { key: 'effect', label: t('super.catalog.columns.effect'), render: (r) => (r as CatalogInteractionRow).effect },
          { key: 'evidence', label: t('super.catalog.drawer.evidence'), render: (r) => (r as CatalogInteractionRow).evidence_level ?? '—' },
          status,
        ];
      default:
        return [
          { key: 'name', label: t('super.catalog.columns.class'), render: (r) => { const a = r as CatalogAllergyClassRow; return (<Box><Typography variant="body2" sx={{ fontWeight: 600 }}>{a.name}</Typography><Typography variant="caption" color="text.secondary">{a.slug}</Typography></Box>); } },
          { key: 'description', label: t('super.catalog.drawer.description'), render: (r) => (r as CatalogAllergyClassRow).description ?? '—' },
          { key: 'members', label: t('super.catalog.columns.members'), align: 'right', render: (r) => n((r as CatalogAllergyClassRow).members_count) },
          { key: 'cross', label: t('super.catalog.drawer.cross_reacts'), align: 'right', render: (r) => n((r as CatalogAllergyClassRow).cross_reacts_with.length) },
          status,
        ];
    }
  })();

  return (
    <Box>
      <Stack spacing={2}>
        <Box sx={{ borderBottom: 1, borderColor: 'divider' }}>
          <Tabs value={tab} onChange={changeTab} variant="scrollable" scrollButtons="auto" allowScrollButtonsMobile aria-label={t('super.catalog.title')}>
            {tabs.map((value) => (
              <Tab key={value} value={value} sx={{ textTransform: 'none' }} label={`${t(`super.catalog.tabs.${value}`)} · ${n(counts[value] ?? 0)}`} />
            ))}
          </Tabs>
        </Box>

        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1} sx={{ alignItems: { sm: 'center' } }}>
          <TextField
            size="small"
            value={q}
            onChange={(e) => { typed.current = true; setQ(e.target.value); }}
            placeholder={t('super.catalog.search_placeholder')}
            slotProps={{ input: { startAdornment: <InputAdornment position="start"><SearchIcon fontSize="small" /></InputAdornment>, 'aria-label': t('super.catalog.search_placeholder') } }}
            sx={{ minWidth: 280 }}
          />
          <TextField select size="small" value={active} onChange={(e) => { setActive(e.target.value as Props['filters']['active']); go({ active: e.target.value, page: 1 }); }} sx={{ minWidth: 160 }} slotProps={{ htmlInput: { 'aria-label': t('super.catalog.filter.label') } }}>
            <MenuItem value="all">{t('super.catalog.filter.all')}</MenuItem>
            <MenuItem value="active">{t('super.catalog.filter.active')}</MenuItem>
            <MenuItem value="inactive">{t('super.catalog.filter.inactive')}</MenuItem>
          </TextField>
          <Box sx={{ flexGrow: 1 }} />
          <Typography variant="caption" color="text.secondary">
            {t(engine === 'meilisearch' ? 'super.catalog.engine.meilisearch' : 'super.catalog.engine.database')}
          </Typography>
        </Stack>

        <SuperTable
          columns={columns}
          rows={rows}
          rowKey={(row) => String(row.id)}
          empty={t('super.catalog.empty')}
          label={t(`super.catalog.tabs.${tab}`)}
          onRowClick={(row) => setSelected(row.id)}
        />

        <Stack direction="row" spacing={1} sx={{ alignItems: 'center', justifyContent: 'flex-end' }}>
          <Typography variant="body2" color="text.secondary">
            {t('super.tenants.page_of', { current: n(meta.current_page), last: n(meta.last_page), total: n(meta.total) })}
          </Typography>
          <Button size="small" startIcon={<ChevronLeftIcon />} disabled={meta.current_page <= 1} onClick={() => go({ page: meta.current_page - 1 })}>{t('super.actions.prev')}</Button>
          <Button size="small" endIcon={<ChevronRightIcon />} disabled={meta.current_page >= meta.last_page} onClick={() => go({ page: meta.current_page + 1 })}>{t('super.actions.next')}</Button>
        </Stack>
      </Stack>

      <DetailDrawer kind={tab} id={selected} onClose={() => setSelected(null)} />
    </Box>
  );
}

Browse.layout = (page: ReactNode) => <PanelLayout title="super.catalog.title">{page}</PanelLayout>;
