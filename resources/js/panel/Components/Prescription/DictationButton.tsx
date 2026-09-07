// <DictationButton> wraps every free-text field (complaints, findings, advice, instruction, notes) — PRESCRIPTION.md
// §4.10. Long-press (or the menu) switches bn-BD ⇄ en-US. Absent API → the button is not rendered at all.
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import IconButton from '@mui/material/IconButton';
import Tooltip from '@mui/material/Tooltip';
import Menu from '@mui/material/MenuItem';
import MuiMenu from '@mui/material/Menu';
import MicIcon from '@mui/icons-material/Mic';
import MicOffIcon from '@mui/icons-material/MicNone';
import { useDictation } from '@panel/hooks/prescription/useDictation';

export interface DictationButtonProps {
  onText: (text: string) => void;
  lang?: string;
  size?: 'small' | 'medium';
  /** Rendered by the parent so `Ctrl+Shift+V` can toggle the focused field. */
  registerToggle?: (toggle: (() => void) | null) => void;
}

export function DictationButton({ onText, lang = 'bn-BD', size = 'small', registerToggle }: DictationButtonProps) {
  const { t } = useTranslation();
  const dictation = useDictation(onText, lang);
  const [anchor, setAnchor] = useState<HTMLElement | null>(null);
  const press = useRef<number | null>(null);
  registerToggle?.(dictation.supported ? dictation.toggle : null);

  if (!dictation.supported) return null;

  const down = (event: React.MouseEvent<HTMLElement>): void => {
    const target = event.currentTarget;
    press.current = window.setTimeout(() => {
      press.current = null;
      setAnchor(target);
    }, 500);
  };
  const up = (): void => {
    if (press.current === null) return;
    window.clearTimeout(press.current);
    press.current = null;
    dictation.toggle();
  };

  return (
    <>
      <Tooltip title={dictation.listening ? t('prescriptions.voice.stop') : t('prescriptions.voice.start', { lang: dictation.lang })}>
        <IconButton tabIndex={-1} size={size} color={dictation.listening ? 'error' : 'default'} onMouseDown={down} onMouseUp={up} onMouseLeave={() => press.current !== null && window.clearTimeout(press.current)} aria-label={t('prescriptions.voice.start', { lang: dictation.lang })}>
          {dictation.listening ? <MicIcon fontSize="inherit" /> : <MicOffIcon fontSize="inherit" />}
        </IconButton>
      </Tooltip>
      <MuiMenu anchorEl={anchor} open={anchor !== null} onClose={() => setAnchor(null)}>
        {['bn-BD', 'en-US'].map((code) => (
          <Menu
            key={code}
            selected={dictation.lang === code}
            onClick={() => {
              dictation.setLang(code);
              setAnchor(null);
            }}
          >
            {code}
          </Menu>
        ))}
      </MuiMenu>
    </>
  );
}

export default DictationButton;
