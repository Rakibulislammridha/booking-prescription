// One Rx line (PRESCRIPTION.md §1.3, §2.13). Two phases in ONE field group: the doctor types a drug fragment, picks
// with Enter, then types the shorthand — `nap` ⏎ `1+0+1 10d af` ⏎. The interpretation of what the parser understood
// is always visible under the line, errors block the commit (and only that line), warnings never do.
import { memo, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import IconButton from '@mui/material/IconButton';
import InputBase from '@mui/material/InputBase';
import Menu from '@mui/material/Menu';
import MenuItem from '@mui/material/MenuItem';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import MoreVertIcon from '@mui/icons-material/MoreVert';
import ErrorIcon from '@mui/icons-material/ErrorOutlined';
import WarningIcon from '@mui/icons-material/WarningAmber';
import type { DrugSearchHit, SafetyAlert } from '@shared/types/models';
import { drugFromHit, drugLabel } from '@panel/lib/prescription/context';
import { resolveRxLineKey } from '@panel/lib/prescription/keyboard';
import { itemErrors, itemInfos, itemWarnings, type FocusPhase, type RxItemDraft } from '@panel/lib/prescription/store/writerStore';
import { useWriterStoreApi } from '@panel/hooks/prescription/useWriterStore';
import { DrugAutocomplete } from './DrugAutocomplete';

export interface RxLineProps {
  item: RxItemDraft;
  index: number;
  isLast: boolean;
  dxCodes: string[];
  lang: 'bn' | 'en';
  alerts: SafetyAlert[];
  onNextZone: () => void;
  onFocusAlert: (fingerprint: string) => void;
  registerFocus: (key: string, focus: ((phase?: FocusPhase) => void) | null) => void;
}

interface PopupState {
  results: DrugSearchHit[];
  highlight: number;
  moveHighlight: (delta: number) => void;
  selected: () => DrugSearchHit | null;
  loading: boolean;
}

export const RxLine = memo(function RxLine({ item, index, isLast, dxCodes, lang, alerts, onNextZone, onFocusAlert, registerFocus }: RxLineProps) {
  const { t } = useTranslation();
  const store = useWriterStoreApi();
  const [drugQuery, setDrugQuery] = useState('');
  const [ghost, setGhost] = useState<string | null>(null);
  const [menu, setMenu] = useState<HTMLElement | null>(null);
  const [committed, setCommitted] = useState(item.shorthand);
  const drugRef = useRef<HTMLInputElement | null>(null);
  const doseRef = useRef<HTMLInputElement | null>(null);
  const anchorRef = useRef<HTMLDivElement | null>(null);
  // State, not a ref: `popupOpen` gates the Enter/arrow keys, so the line must re-render when hits arrive.
  const [popup, setPopup] = useState<PopupState>({ results: [], highlight: 0, moveHighlight: () => undefined, selected: () => null, loading: false });

  const errors = itemErrors(item);
  const warnings = itemWarnings(item);
  const infos = itemInfos(item);
  const drugPhase = item.drug === null;
  const popupOpen = drugPhase && drugQuery.trim().length >= 2 && popup.results.length > 0;
  const critical = alerts.filter((a) => a.severity === 'critical');
  const warned = alerts.filter((a) => a.severity === 'warning');
  const display = item.display?.[lang] ?? null;

  useEffect(() => {
    registerFocus(item.key, (phase) => {
      const target = phase === 'drug' || item.drug === null ? drugRef.current : doseRef.current;
      target?.focus();
      if (target === null || target !== doseRef.current) return;
      // `dose_all` selects what is already there (a quick-pick insert pre-fills the doctor's last dose, so typing
      // replaces it and Enter keeps it); anything else parks the caret at the end so typing appends.
      if (phase === 'dose_all') target.select();
      else target.setSelectionRange(target.value.length, target.value.length);
    });
    return () => registerFocus(item.key, null);
  }, [item.key, item.drug, registerFocus]);

  const pick = (hit: DrugSearchHit): void => {
    store.getState().setDrug(item.key, drugFromHit(hit));
    setDrugQuery('');
    if (item.shorthand === '' && hit.last_shorthand) setGhost(hit.last_shorthand);
    window.setTimeout(() => doseRef.current?.focus(), 0);
  };

  const commit = (): boolean => {
    store.getState().commitItem(item.key);
    setCommitted(item.shorthand);
    return itemErrors(store.getState().items.find((i) => i.key === item.key) ?? item).length === 0;
  };

  const handleKey = (event: React.KeyboardEvent<HTMLInputElement>): void => {
    const caret = event.currentTarget.selectionStart ?? 0;
    const action = resolveRxLineKey(event, {
      popupOpen,
      phase: drugPhase ? 'drug' : 'dose',
      caret,
      length: event.currentTarget.value.length,
      hasGhost: ghost !== null && item.shorthand === '',
      hasError: errors.length > 0,
      dirty: item.shorthand !== committed,
      hasAlert: alerts.length > 0,
      isLast,
    });
    if (action === null) return;
    event.preventDefault();

    const state = store.getState();
    switch (action.type) {
      case 'popup_move':
        popup.moveHighlight(action.delta);
        break;
      case 'popup_select': {
        const hit = popup.selected();
        if (hit !== null) pick(hit);
        break;
      }
      case 'popup_close':
        setPopup((p) => ({ ...p, results: [] })); // keep the typed text, close the list
        break;
      case 'commit_and_new': {
        if (!commit()) break;
        const nextItem = state.items[index + 1];
        if (nextItem !== undefined && nextItem.drug === null && nextItem.shorthand === '') state.setFocus('rx', nextItem.key);
        else state.addItem(index + 1);
        break;
      }
      case 'commit_and_stay':
        commit();
        break;
      case 'commit_and_next': {
        if (!commit()) break;
        const nextItem = state.items[index + 1];
        if (nextItem === undefined) onNextZone();
        else state.setFocus('rx', nextItem.key);
        break;
      }
      case 'move_line': {
        const target = state.items[index + action.delta];
        if (target !== undefined) state.setFocus('rx', target.key);
        else if (action.delta > 0) onNextZone();
        break;
      }
      case 'reorder':
        state.moveItem(item.key, action.delta);
        break;
      case 'duplicate':
        state.duplicateItem(item.key);
        break;
      case 'delete':
        state.removeItem(item.key);
        break;
      case 'edit_drug':
        setDrugQuery(item.drug?.brand_name ?? item.drug?.generic_name ?? '');
        state.setDrug(item.key, null);
        window.setTimeout(() => drugRef.current?.focus(), 0);
        break;
      case 'accept_ghost':
        if (ghost !== null) {
          state.setShorthand(item.key, ghost);
          setGhost(null);
        }
        break;
      case 'revert':
        state.setShorthand(item.key, committed);
        break;
      case 'focus_alert': {
        const alert = critical[0] ?? warned[0] ?? alerts[0];
        if (alert !== undefined) onFocusAlert(alert.fingerprint);
        break;
      }
    }
  };

  const applySuggestion = (token: string | null, suggestion: string): void => {
    if (token === null) return;
    store.getState().setShorthand(item.key, item.shorthand.replace(new RegExp(`\\b${token}\\b`, 'i'), suggestion));
    doseRef.current?.focus();
  };

  const moveToInstruction = (token: string | null): void => {
    if (token === null) return;
    const rest = item.shorthand.replace(new RegExp(`\\b${token}\\b`, 'i'), '').replace(/\s+/g, ' ').trim();
    store.getState().setShorthand(item.key, `${rest} // ${token}`);
    doseRef.current?.focus();
  };

  const severityColour = errors.length > 0 ? 'error.main' : critical.length > 0 ? 'error.main' : warnings.length > 0 || warned.length > 0 ? 'warning.main' : 'divider';

  return (
    <Box
      data-testid={`rx-line-${index}`}
      sx={{
        px: 1,
        py: 0.5,
        borderLeft: 3,
        borderColor: severityColour,
        borderBottom: 1,
        borderBottomColor: 'divider',
        bgcolor: item.status === 'error' ? 'error.50' : 'transparent',
        '&:hover': { bgcolor: 'action.hover' },
      }}
    >
      <Box ref={anchorRef} sx={{ display: 'flex', alignItems: 'center', gap: 1, minHeight: 30 }}>
        <Typography variant="caption" sx={{ width: 16, color: 'text.secondary', textAlign: 'right' }}>
          {index + 1}
        </Typography>

        {drugPhase ? (
          <InputBase
            inputRef={drugRef}
            value={drugQuery}
            onChange={(e) => setDrugQuery(e.target.value)}
            onKeyDown={handleKey}
            placeholder={t('prescriptions.rx.search_drug')}
            inputProps={{ 'aria-label': t('prescriptions.rx.search_drug'), autoComplete: 'off', spellCheck: false }}
            sx={{ flex: '0 0 260px', fontSize: 14, fontWeight: 600 }}
          />
        ) : (
          <Tooltip title={item.drug?.generic_name ?? ''}>
            <Chip
              size="small"
              color={item.drug?.kind === 'custom' ? 'secondary' : 'default'}
              variant={item.drug?.kind === 'generic' ? 'outlined' : 'filled'}
              label={drugLabel(item.drug)}
              onDelete={() => store.getState().setDrug(item.key, null)}
              tabIndex={-1}
              sx={{ flex: '0 0 auto', maxWidth: 260, fontWeight: 600 }}
            />
          </Tooltip>
        )}

        <Box sx={{ position: 'relative', flexGrow: 1, minWidth: 120 }}>
          <InputBase
            inputRef={doseRef}
            value={item.shorthand}
            disabled={drugPhase}
            onChange={(e) => {
              store.getState().setShorthand(item.key, e.target.value);
              if (e.target.value !== '') setGhost(null);
            }}
            onKeyDown={handleKey}
            onBlur={() => store.getState().commitItem(item.key)}
            placeholder={t('prescriptions.rx.shorthand_placeholder')}
            inputProps={{ 'aria-label': t('prescriptions.rx.shorthand'), autoComplete: 'off', spellCheck: false }}
            sx={{ width: '100%', fontFamily: 'monospace', fontSize: 14 }}
          />
          {ghost !== null && item.shorthand === '' ? (
            <Typography variant="caption" sx={{ position: 'absolute', left: 0, top: 6, color: 'text.disabled', fontFamily: 'monospace', pointerEvents: 'none' }}>
              {ghost} <span style={{ fontFamily: 'inherit' }}>→</span>
            </Typography>
          ) : null}
        </Box>

        {item.parsed?.quantity.value != null ? (
          <Tooltip title={item.parsed.quantity.basis ?? ''}>
            <Chip size="small" variant="outlined" label={`${item.parsed.quantity.value} ${item.parsed.quantity.unit}`} sx={{ height: 20, fontSize: 11 }} />
          </Tooltip>
        ) : null}

        {critical.length > 0 ? (
          <Tooltip title={critical[0]?.title ?? ''}>
            <IconButton tabIndex={-1} size="small" color="error" onClick={() => onFocusAlert(critical[0]?.fingerprint ?? '')} aria-label={t('prescriptions.alerts.review_critical', { count: critical.length })}>
              <ErrorIcon fontSize="inherit" />
            </IconButton>
          </Tooltip>
        ) : warned.length > 0 ? (
          <Tooltip title={warned[0]?.title ?? ''}>
            <IconButton tabIndex={-1} size="small" color="warning" onClick={() => onFocusAlert(warned[0]?.fingerprint ?? '')} aria-label={warned[0]?.title ?? ''}>
              <WarningIcon fontSize="inherit" />
            </IconButton>
          </Tooltip>
        ) : null}

        <IconButton tabIndex={-1} size="small" onClick={(e) => setMenu(e.currentTarget)} aria-label={t('prescriptions.rx.line_menu')}>
          <MoreVertIcon fontSize="inherit" />
        </IconButton>
        <Menu anchorEl={menu} open={menu !== null} onClose={() => setMenu(null)}>
          <MenuItem
            onClick={() => {
              store.getState().duplicateItem(item.key);
              setMenu(null);
            }}
          >
            {t('prescriptions.rx.duplicate')}
          </MenuItem>
          <MenuItem
            onClick={() => {
              store.getState().moveItem(item.key, -1);
              setMenu(null);
            }}
          >
            {t('prescriptions.rx.move_up')}
          </MenuItem>
          <MenuItem
            onClick={() => {
              store.getState().moveItem(item.key, 1);
              setMenu(null);
            }}
          >
            {t('prescriptions.rx.move_down')}
          </MenuItem>
          <MenuItem
            onClick={() => {
              store.getState().removeItem(item.key);
              setMenu(null);
            }}
          >
            {t('prescriptions.rx.delete')}
          </MenuItem>
        </Menu>
      </Box>

      {display !== null && display.interpretation !== '' && errors.length === 0 ? (
        <Typography variant="caption" sx={{ display: 'block', pl: 3, color: 'text.secondary' }} data-testid="rx-interpretation">
          {display.interpretation}
        </Typography>
      ) : null}

      {errors.map((issue, i) => (
        <Box key={`e${i}`} sx={{ pl: 3, display: 'flex', alignItems: 'center', gap: 1 }}>
          <Typography variant="caption" color="error" data-testid="rx-error">
            {issue.message}
          </Typography>
          {issue.suggestion !== null ? (
            <Chip size="small" color="primary" variant="outlined" sx={{ height: 18, fontSize: 11 }} label={t('prescriptions.rx.did_you_mean', { token: issue.suggestion })} onClick={() => applySuggestion(issue.token, issue.suggestion ?? '')} />
          ) : null}
          {issue.token !== null ? (
            <Chip size="small" variant="outlined" sx={{ height: 18, fontSize: 11 }} label={t('prescriptions.rx.move_to_instruction')} onClick={() => moveToInstruction(issue.token)} />
          ) : null}
        </Box>
      ))}

      {errors.length === 0 && (warnings.length > 0 || infos.length > 0) ? (
        <Box sx={{ pl: 3, display: 'flex', gap: 1, flexWrap: 'wrap' }}>
          {warnings.map((issue, i) => (
            <Typography key={`w${i}`} variant="caption" color="warning.main">
              {issue.message}
            </Typography>
          ))}
          {infos.map((issue, i) => (
            <Typography key={`i${i}`} variant="caption" color="text.disabled">
              {issue.message}
            </Typography>
          ))}
        </Box>
      ) : null}

      <DrugAutocomplete
        query={drugQuery}
        anchorEl={anchorRef.current}
        open={drugPhase}
        dxCodes={dxCodes}
        onPick={pick}
        onState={setPopup}
      />
    </Box>
  );
});

export default RxLine;
