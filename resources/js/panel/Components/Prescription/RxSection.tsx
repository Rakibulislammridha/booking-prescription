// The Rx block: the list of lines plus the affordances that keep the common path mouse-free — the `?` cheat-sheet
// trigger, "add line", and the save-as-template action. Focus is routed here: the store's focus.itemKey is the one
// source of truth, so quick-pick inserts, Enter-to-next-line and Alt+5 all land in the same place.
import { useCallback, useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import IconButton from '@mui/material/IconButton';
import Paper from '@mui/material/Paper';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import AddIcon from '@mui/icons-material/Add';
import HelpIcon from '@mui/icons-material/HelpOutlined';
import SaveTemplateIcon from '@mui/icons-material/BookmarkAdd';
import type { SafetyAlert } from '@shared/types/models';
import { alertsForItem, type RxItemDraft } from '@panel/lib/prescription/store/writerStore';
import { useWriterStoreApi } from '@panel/hooks/prescription/useWriterStore';
import { RxLine } from './RxLine';

export interface RxSectionProps {
  items: RxItemDraft[];
  alerts: SafetyAlert[];
  dxCodes: string[];
  lang: 'bn' | 'en';
  focusKey: string | undefined;
  containerRef?: React.RefObject<HTMLDivElement | null>;
  onNextZone: () => void;
  onCheatsheet: () => void;
  onSaveTemplate: () => void;
  onFocusAlert: (fingerprint: string) => void;
  /** Lets the page insert text into the focused line (cheat-sheet examples, quick-pick doses). */
  registerInsert: (insert: (text: string) => void) => void;
}

export function RxSection({ items, alerts, dxCodes, lang, focusKey, containerRef, onNextZone, onCheatsheet, onSaveTemplate, onFocusAlert, registerInsert }: RxSectionProps) {
  const { t } = useTranslation();
  const store = useWriterStoreApi();
  const focusers = useRef(new Map<string, (phase?: 'drug' | 'dose') => void>());

  const registerFocus = useCallback((key: string, focus: ((phase?: 'drug' | 'dose') => void) | null) => {
    if (focus === null) focusers.current.delete(key);
    else focusers.current.set(key, focus);
  }, []);

  useEffect(() => {
    if (focusKey === undefined) return;
    const focus = focusers.current.get(focusKey);
    if (focus !== undefined) window.setTimeout(() => focus(), 0);
  }, [focusKey, items.length]);

  useEffect(() => {
    registerInsert((text: string) => {
      const state = store.getState();
      const key = state.focus.itemKey ?? state.items[state.items.length - 1]?.key;
      if (key === undefined) {
        state.addItem(undefined, { shorthand: text });
        return;
      }
      const item = state.items.find((i) => i.key === key);
      state.setShorthand(key, item !== undefined && item.shorthand !== '' ? `${item.shorthand} ${text}` : text);
      focusers.current.get(key)?.('dose');
    });
  }, [registerInsert, store]);

  return (
    <Paper variant="outlined" ref={containerRef} tabIndex={-1}>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, px: 1, py: 0.25, borderBottom: 1, borderColor: 'divider' }}>
        <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary' }}>
          {t('prescriptions.writer.zones.rx')}
        </Typography>
        <Tooltip title={t('prescriptions.cheatsheet.open')}>
          <IconButton tabIndex={-1} size="small" onClick={onCheatsheet} aria-label={t('prescriptions.cheatsheet.open')}>
            <HelpIcon fontSize="inherit" />
          </IconButton>
        </Tooltip>
        <Box sx={{ flexGrow: 1 }} />
        <Tooltip title={t('prescriptions.templates.save_as')}>
          <IconButton tabIndex={-1} size="small" onClick={onSaveTemplate} aria-label={t('prescriptions.templates.save_as')}>
            <SaveTemplateIcon fontSize="inherit" />
          </IconButton>
        </Tooltip>
      </Box>

      {items.map((item, index) => (
        <RxLine
          key={item.key}
          item={item}
          index={index}
          isLast={index === items.length - 1}
          dxCodes={dxCodes}
          lang={lang}
          alerts={alertsForItem(alerts, item.key)}
          onNextZone={onNextZone}
          onFocusAlert={onFocusAlert}
          registerFocus={registerFocus}
        />
      ))}

      <Box sx={{ px: 1, py: 0.25 }}>
        <Button tabIndex={-1} size="small" startIcon={<AddIcon />} onClick={() => store.getState().addItem()}>
          {t('prescriptions.rx.add_line')}
        </Button>
      </Box>
    </Paper>
  );
}

export default RxSection;
