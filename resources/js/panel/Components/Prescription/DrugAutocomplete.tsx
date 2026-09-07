// Drug picker (PRESCRIPTION.md §3.3): federated catalog + tenant custom brands in one debounced list, boosted by the
// doctor's usage and by the diagnoses currently on the pad. Every row shows brand · strength · form and the generic
// underneath, so two molecules are never confused; custom brands carry a visible "clinic" marker and their review
// state; the "any brand" generic row is always reachable.
import { useEffect, useMemo, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import LinearProgress from '@mui/material/LinearProgress';
import ListItemButton from '@mui/material/ListItemButton';
import Paper from '@mui/material/Paper';
import Popper from '@mui/material/Popper';
import Typography from '@mui/material/Typography';
import StarIcon from '@mui/icons-material/Star';
import type { DrugSearchHit } from '@shared/types/models';
import { searchDrugs } from '@panel/api/prescription';
import { useDebouncedSearch } from '@panel/hooks/prescription/useDebouncedSearch';

export interface DrugAutocompleteProps {
  query: string;
  anchorEl: HTMLElement | null;
  open: boolean;
  dxCodes: string[];
  onPick: (hit: DrugSearchHit) => void;
  /** The parent owns the keyboard: it reads `highlight` / `results` through this callback. */
  onState: (state: { results: DrugSearchHit[]; highlight: number; moveHighlight: (delta: number) => void; selected: () => DrugSearchHit | null; loading: boolean }) => void;
}

export function DrugAutocomplete({ query, anchorEl, open, dxCodes, onPick, onState }: DrugAutocompleteProps) {
  const { t } = useTranslation();
  const dx = useMemo(() => dxCodes.filter((c) => c !== ''), [dxCodes]);
  const search = useDebouncedSearch<DrugSearchHit>(async (q, signal) => (await searchDrugs(q, { dx, limit: 12 }, signal)).hits, { deps: [dx.join(',')] });
  const listRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    search.setQuery(query);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query]);

  useEffect(() => {
    onState({ results: search.results, highlight: search.highlight, moveHighlight: search.moveHighlight, selected: search.selected, loading: search.loading });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search.results, search.highlight, search.loading]);

  useEffect(() => {
    const row = listRef.current?.querySelector('[data-highlighted="true"]');
    if (row instanceof HTMLElement && typeof row.scrollIntoView === 'function') row.scrollIntoView({ block: 'nearest' });
  }, [search.highlight]);

  const show = open && (search.results.length > 0 || search.loading);

  return (
    <Popper open={show} anchorEl={anchorEl} placement="bottom-start" style={{ zIndex: 1300 }} modifiers={[{ name: 'offset', options: { offset: [0, 4] } }]}>
      <Paper elevation={8} sx={{ width: 460, maxHeight: 340, overflowY: 'auto' }} ref={listRef} role="listbox" aria-label={t('prescriptions.rx.search_drug')}>
        {search.loading ? <LinearProgress /> : null}
        {search.results.map((hit, index) => (
          <ListItemButton
            key={hit.id}
            dense
            selected={index === search.highlight}
            data-highlighted={index === search.highlight ? 'true' : 'false'}
            role="option"
            aria-selected={index === search.highlight}
            onMouseDown={(e) => e.preventDefault()}
            onClick={() => onPick(hit)}
            sx={{ alignItems: 'flex-start', py: 0.5 }}
          >
            <Box sx={{ minWidth: 0, flexGrow: 1 }}>
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.75 }}>
                <Typography variant="body2" sx={{ fontWeight: 600 }} noWrap>
                  {hit.doc_type === 'generic' ? hit.generic_name : hit.brand_name ?? hit.generic_name}
                </Typography>
                {hit.strength_label ? (
                  <Typography variant="body2" color="text.secondary" noWrap>
                    {hit.strength_label}
                  </Typography>
                ) : null}
                {hit.form ? (
                  <Chip label={hit.form} size="small" variant="outlined" sx={{ height: 18, fontSize: 11 }} />
                ) : null}
                {hit.source === 'custom' ? <Chip color="secondary" label={t('prescriptions.rx.custom_brand')} size="small" sx={{ height: 18, fontSize: 11 }} /> : null}
                {hit.doc_type === 'generic' ? <Chip label={t('prescriptions.rx.any_brand')} size="small" variant="outlined" sx={{ height: 18, fontSize: 11 }} /> : null}
                {hit.fav_for_dx ? <StarIcon color="warning" sx={{ fontSize: 14 }} titleAccess={t('prescriptions.quick.for_dx')} /> : null}
              </Box>
              <Typography variant="caption" color="text.secondary" noWrap component="div">
                {hit.generic_name}
                {hit.manufacturer ? ` · ${hit.manufacturer}` : ''}
                {hit.usage > 0 ? ` · ${t('prescriptions.rx.used_times', { count: hit.usage })}` : ''}
                {hit.review_status === 'pending' ? ` · ${t('prescriptions.rx.review_pending')}` : ''}
              </Typography>
            </Box>
            {hit.last_shorthand ? (
              <Typography variant="caption" sx={{ ml: 1, fontFamily: 'monospace', color: 'primary.main', whiteSpace: 'nowrap' }}>
                {hit.last_shorthand} →
              </Typography>
            ) : null}
          </ListItemButton>
        ))}
      </Paper>
    </Popper>
  );
}

export default DrugAutocomplete;
