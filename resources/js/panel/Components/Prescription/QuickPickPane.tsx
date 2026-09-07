// RIGHT pane (§1.1, §3.5–§3.7): everything that makes a routine line ONE click. "For Dx" learns per diagnosis, "Top
// 50" is the doctor's own ranked list, then templates, the clinic's investigation catalog and the advice library.
// Ctrl+K focuses the search box, Esc returns to the last Rx line. The pane is never in the Tab order.
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Badge from '@mui/material/Badge';
import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import InputAdornment from '@mui/material/InputAdornment';
import ListItemButton from '@mui/material/ListItemButton';
import Paper from '@mui/material/Paper';
import Stack from '@mui/material/Stack';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import PushPinIcon from '@mui/icons-material/PushPin';
import SearchIcon from '@mui/icons-material/Search';
import { formatBdt } from '@shared/format/money';
import type { AdviceSnippet, InvestigationCatalogRow, TemplateBrief, TopDrug } from '@shared/types/models';
import { fetchFavourites } from '@panel/api/prescription';

export type QuickPickTab = 'dx' | 'top' | 'templates' | 'tests' | 'advice';

export interface QuickPickPaneProps {
  topDrugs: TopDrug[];
  templates: TemplateBrief[];
  snippets: AdviceSnippet[];
  investigations: InvestigationCatalogRow[];
  dxCodes: string[];
  searchRef?: React.RefObject<HTMLInputElement | null>;
  onInsertDrug: (drug: TopDrug) => void;
  onApplyTemplate: (template: TemplateBrief) => void;
  onInsertInvestigation: (row: InvestigationCatalogRow) => void;
  onInsertAdvice: (snippet: AdviceSnippet) => void;
  onEscape: () => void;
}

export function QuickPickPane({ topDrugs, templates, snippets, investigations, dxCodes, searchRef, onInsertDrug, onApplyTemplate, onInsertInvestigation, onInsertAdvice, onEscape }: QuickPickPaneProps) {
  const { t } = useTranslation();
  const primaryDx = dxCodes[0] ?? null;
  const [tab, setTab] = useState<QuickPickTab>(primaryDx !== null ? 'dx' : 'top');
  const [query, setQuery] = useState('');
  const [dxDrugs, setDxDrugs] = useState<TopDrug[]>([]);

  useEffect(() => {
    if (primaryDx === null) {
      setDxDrugs([]);
      return;
    }
    const controller = new AbortController();
    fetchFavourites(primaryDx, controller.signal)
      .then(setDxDrugs)
      .catch(() => setDxDrugs([]));
    setTab('dx');
    return () => controller.abort();
  }, [primaryDx]);

  const q = query.trim().toLowerCase();
  const match = (text: string): boolean => q === '' || text.toLowerCase().includes(q);

  const protocols = useMemo(() => templates.filter((tpl) => tpl.icd10_code !== null && primaryDx !== null && tpl.icd10_code === primaryDx), [templates, primaryDx]);
  const shownDrugs = (tab === 'dx' ? dxDrugs : topDrugs).filter((d) => match(d.label));
  const shownTemplates = templates.filter((tpl) => match(tpl.name) || match(tpl.shorthand ?? ''));
  const shownTests = investigations.filter((row) => match(row.name) || match(row.code ?? ''));
  const shownSnippets = snippets.filter((s) => match(s.text) || match(s.text_bn ?? '') || match(s.shorthand ?? ''));

  const drugRow = (drug: TopDrug) => (
    <ListItemButton key={`${drug.id}-${drug.drug.presentation_key}`} dense onClick={() => onInsertDrug(drug)} sx={{ py: 0.25 }}>
      <Box sx={{ minWidth: 0, flexGrow: 1 }}>
        <Typography variant="body2" noWrap sx={{ fontWeight: 600 }}>
          {drug.label}
        </Typography>
        {drug.default_dose.shorthand ? (
          <Typography variant="caption" sx={{ fontFamily: 'monospace', color: 'primary.main' }}>
            {drug.default_dose.shorthand}
          </Typography>
        ) : null}
      </Box>
      {drug.is_pinned ? <PushPinIcon sx={{ fontSize: 14 }} color="warning" /> : null}
      <Typography variant="caption" color="text.secondary">
        {drug.use_count}
      </Typography>
    </ListItemButton>
  );

  return (
    <Paper variant="outlined" sx={{ height: '100%', display: 'flex', flexDirection: 'column', minHeight: 0 }} onKeyDown={(e) => e.key === 'Escape' && onEscape()}>
      <TextField
        size="small"
        inputRef={searchRef}
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        placeholder={t('prescriptions.quick.search')}
        sx={{ m: 0.5 }}
        slotProps={{
          htmlInput: { 'aria-label': t('prescriptions.quick.search') },
          input: { startAdornment: <InputAdornment position="start"><SearchIcon fontSize="small" /></InputAdornment> },
        }}
      />
      <Tabs value={tab} onChange={(_, next: QuickPickTab) => setTab(next)} variant="scrollable" scrollButtons={false} sx={{ minHeight: 32, '& .MuiTab-root': { minHeight: 32, fontSize: 12, px: 1 } }}>
        <Tab value="dx" label={<Badge color="primary" badgeContent={dxDrugs.length} max={99}>{t('prescriptions.quick.for_dx')}</Badge>} />
        <Tab value="top" label={t('prescriptions.quick.top50')} />
        <Tab value="templates" label={t('prescriptions.quick.templates')} />
        <Tab value="tests" label={t('prescriptions.quick.tests')} />
        <Tab value="advice" label={t('prescriptions.quick.advice')} />
      </Tabs>

      <Box sx={{ flexGrow: 1, overflowY: 'auto', minHeight: 0 }}>
        {tab === 'dx' && protocols.length > 0 ? (
          <Box sx={{ px: 1, py: 0.5 }}>
            <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary' }}>
              {t('prescriptions.quick.protocols')}
            </Typography>
            <Stack direction="row" spacing={0.5} sx={{ flexWrap: 'wrap', gap: 0.5 }}>
              {protocols.map((tpl) => (
                <Chip key={tpl.id} size="small" color="primary" sx={{ height: 22 }} label={tpl.name} onClick={() => onApplyTemplate(tpl)} />
              ))}
            </Stack>
          </Box>
        ) : null}

        {(tab === 'dx' || tab === 'top') ? (
          shownDrugs.length === 0 ? (
            <Typography variant="caption" color="text.secondary" sx={{ p: 1, display: 'block' }}>
              {tab === 'dx' && primaryDx === null ? t('prescriptions.quick.pick_dx_first') : t('common.status.none')}
            </Typography>
          ) : (
            shownDrugs.map(drugRow)
          )
        ) : null}

        {tab === 'templates'
          ? shownTemplates.map((tpl) => (
              <ListItemButton key={tpl.id} dense onClick={() => onApplyTemplate(tpl)} sx={{ py: 0.25 }}>
                <Box sx={{ minWidth: 0, flexGrow: 1 }}>
                  <Typography variant="body2" noWrap sx={{ fontWeight: 600 }}>
                    {tpl.name}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    {tpl.shorthand ?? ''} {t('prescriptions.quick.item_count', { count: tpl.item_count })}
                    {tpl.is_shared ? ` · ${t('prescriptions.quick.shared')}` : ''}
                  </Typography>
                </Box>
              </ListItemButton>
            ))
          : null}

        {tab === 'tests'
          ? shownTests.map((row) => (
              <ListItemButton key={row.id} dense onClick={() => onInsertInvestigation(row)} sx={{ py: 0.25 }}>
                <Box sx={{ minWidth: 0, flexGrow: 1 }}>
                  <Typography variant="body2" noWrap>
                    {row.name}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    {row.category}
                    {row.prep_instructions ? ' · ' : ''}
                    {row.prep_instructions ?? ''}
                  </Typography>
                </Box>
                <Typography variant="caption" sx={{ fontWeight: 600 }}>
                  {formatBdt(row.price_paisa)}
                </Typography>
              </ListItemButton>
            ))
          : null}

        {tab === 'advice'
          ? shownSnippets.map((snippet) => (
              <ListItemButton key={snippet.id} dense onClick={() => onInsertAdvice(snippet)} sx={{ py: 0.25 }}>
                <Tooltip title={snippet.text}>
                  <Box sx={{ minWidth: 0 }}>
                    <Typography variant="body2" noWrap>
                      {snippet.text_bn ?? snippet.text}
                    </Typography>
                    <Typography variant="caption" color="text.secondary">
                      {snippet.shorthand ?? ''} {snippet.category ?? ''}
                    </Typography>
                  </Box>
                </Tooltip>
              </ListItemButton>
            ))
          : null}
      </Box>
    </Paper>
  );
}

export default QuickPickPane;
