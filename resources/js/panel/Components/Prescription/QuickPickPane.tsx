// RIGHT pane (§1.1, §3.5–§3.7): everything that makes a routine line ONE click. "For Dx" learns per diagnosis, "Top
// 50" is the doctor's own ranked list, then templates, the clinic's investigation catalog and the advice library.
// Ctrl+K focuses the search box, Esc returns to the last Rx line. The pane is never in the Tab order.
//
// One CLICK inserts a drug; one KEYSTROKE does too — from the search box ↑/↓ move the highlight and ⏎ inserts the
// highlighted row, so `Ctrl+K` `nap` `⏎` is a complete line (drug + the doctor's last dose for it) without leaving
// the keyboard. Rows are one line tall on purpose: the top of a doctor's top-50 has to be readable without
// scrolling, and the dose he last used is the thing he is scanning for.
import { useEffect, useMemo, useRef, useState } from 'react';
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
  const [highlight, setHighlight] = useState(0);
  const listRef = useRef<HTMLDivElement | null>(null);

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
  const onDrugTab = tab === 'dx' || tab === 'top';
  const cursor = shownDrugs.length === 0 ? 0 : Math.min(highlight, shownDrugs.length - 1);
  const shownTemplates = templates.filter((tpl) => match(tpl.name) || match(tpl.shorthand ?? ''));
  const shownTests = investigations.filter((row) => match(row.name) || match(row.code ?? ''));
  const shownSnippets = snippets.filter((s) => match(s.text) || match(s.text_bn ?? '') || match(s.shorthand ?? ''));

  // Keep the keyboard highlight sane as the list changes under it, and scroll it into view.
  useEffect(() => {
    setHighlight(0);
  }, [tab, query, dxDrugs.length]);

  useEffect(() => {
    if (!onDrugTab) return;
    const row = listRef.current?.querySelector('[data-quick-active="true"]');
    if (row instanceof HTMLElement && typeof row.scrollIntoView === 'function') row.scrollIntoView({ block: 'nearest' });
  }, [cursor, onDrugTab]);

  const onSearchKey = (event: React.KeyboardEvent): void => {
    if (!onDrugTab || shownDrugs.length === 0) return;
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      const delta = event.key === 'ArrowDown' ? 1 : -1;
      setHighlight((h) => (Math.min(h, shownDrugs.length - 1) + delta + shownDrugs.length) % shownDrugs.length);
      return;
    }
    if (event.key === 'Enter') {
      const drug = shownDrugs[cursor];
      if (drug === undefined) return;
      event.preventDefault();
      onInsertDrug(drug);           // focus follows the insert into the line's dose field (Writer.insertTopDrug)
    }
  };

  const drugRow = (drug: TopDrug, index: number) => (
    <ListItemButton
      key={`${drug.id}-${drug.drug.presentation_key}`}
      dense
      data-testid="quick-drug"
      data-quick-active={onDrugTab && index === cursor ? 'true' : 'false'}
      selected={onDrugTab && index === cursor}
      onClick={() => onInsertDrug(drug)}
      sx={{ py: 0.1, minHeight: 26, gap: 0.5 }}
    >
      {/* The use count moved into the tooltip: the list is already ranked by it, and the width buys the dose. */}
      <Tooltip title={`${drug.label} · ${t('prescriptions.rx.used_times', { count: drug.use_count })}`}>
        <Typography variant="body2" noWrap sx={{ fontWeight: 600, minWidth: 0, flexGrow: 1, fontSize: 12.5 }}>
          {drug.label}
        </Typography>
      </Tooltip>
      {/* The dose he last prescribed for this drug — pre-filled by the insert, which is why it is worth the width. */}
      {drug.default_dose.shorthand ? (
        <Typography variant="caption" sx={{ fontFamily: 'monospace', color: 'primary.main', fontSize: 11, whiteSpace: 'nowrap' }}>
          {drug.default_dose.shorthand}
        </Typography>
      ) : null}
      {drug.is_pinned ? <PushPinIcon sx={{ fontSize: 13 }} color="warning" /> : null}
    </ListItemButton>
  );

  return (
    <Paper variant="outlined" sx={{ height: '100%', display: 'flex', flexDirection: 'column', minHeight: 0 }} onKeyDown={(e) => e.key === 'Escape' && onEscape()}>
      <TextField
        size="small"
        inputRef={searchRef}
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        onKeyDown={onSearchKey}
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

      <Box ref={listRef} sx={{ flexGrow: 1, overflowY: 'auto', minHeight: 0 }}>
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

        {onDrugTab ? (
          shownDrugs.length === 0 ? (
            <Typography variant="caption" color="text.secondary" sx={{ p: 1, display: 'block' }}>
              {tab === 'dx' && primaryDx === null ? t('prescriptions.quick.pick_dx_first') : t('common.status.none')}
            </Typography>
          ) : (
            <>
              <Typography variant="caption" sx={{ px: 1, display: 'block', fontSize: 10, lineHeight: 1.4, color: 'text.secondary' }}>
                {t('prescriptions.quick.insert_hint')}
              </Typography>
              {shownDrugs.map(drugRow)}
            </>
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
