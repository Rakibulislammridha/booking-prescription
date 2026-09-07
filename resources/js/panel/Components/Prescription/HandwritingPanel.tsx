// Handwriting mode (§4.12) — the adoption bridge, first class. The canvas has the aspect ratio of the pad body area
// for the doctor's paper, so what is written lands where it prints. Up to 3 pages, undo/redo in memory, and every
// 2 s pause after a stroke uploads that page as a PNG. Structured Rx lines (if any) still print after the pages,
// and the Issue dialog carries the fixed "handwritten content is not safety-checked" warning.
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import IconButton from '@mui/material/IconButton';
import Paper from '@mui/material/Paper';
import Stack from '@mui/material/Stack';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import RedoIcon from '@mui/icons-material/Redo';
import UndoIcon from '@mui/icons-material/Undo';
import { saveHandwritingPage } from '@panel/api/prescription';
import { StrokeCanvas, type StrokeCanvasHandle, type Tool } from './StrokeCanvas';

/** Pad body area aspect ratios (§7.2): A4 210×297 and A5 148×210 minus the header band. */
const ASPECT: Record<'A4' | 'A5', number> = { A4: 297 / 210, A5: 210 / 148 };
const MAX_PAGES = 3;

export interface HandwritingPanelProps {
  prescriptionId: string;
  paperSize: 'A4' | 'A5';
  width: number;
  onSaved: (page: number, path: string) => void;
}

export function HandwritingPanel({ prescriptionId, paperSize, width, onSaved }: HandwritingPanelProps) {
  const { t } = useTranslation();
  const canvas = useRef<StrokeCanvasHandle | null>(null);
  const [page, setPage] = useState(1);
  const [pages, setPages] = useState(1);
  const [tool, setTool] = useState<Tool>('pen');
  const [saved, setSaved] = useState<Record<number, boolean>>({});
  const [error, setError] = useState<string | null>(null);
  const height = Math.round(width * (ASPECT[paperSize] ?? ASPECT.A4) * 0.78);

  const upload = async (): Promise<void> => {
    if (canvas.current === null || canvas.current.isEmpty()) return;
    const png = await canvas.current.toPng(Math.min(2, 2480 / width));
    if (png === null) return;
    try {
      const result = await saveHandwritingPage(prescriptionId, page, png);
      setSaved((s) => ({ ...s, [page]: true }));
      setError(null);
      onSaved(result.page, result.path);
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
    }
  };

  return (
    <Paper variant="outlined" sx={{ p: 1 }}>
      <Stack direction="row" spacing={1} sx={{ alignItems: 'center', mb: 1, flexWrap: 'wrap' }}>
        <Typography variant="caption" sx={{ fontWeight: 700 }}>
          {t('prescriptions.handwriting.title')}
        </Typography>
        {Array.from({ length: pages }, (_, i) => i + 1).map((n) => (
          <Chip key={n} size="small" sx={{ height: 22 }} color={page === n ? 'primary' : 'default'} variant={saved[n] ? 'filled' : 'outlined'} label={t('prescriptions.handwriting.page', { page: n })} onClick={() => setPage(n)} />
        ))}
        {pages < MAX_PAGES ? (
          <Button
            size="small"
            onClick={() => {
              void upload();
              setPages(pages + 1);
              setPage(pages + 1);
              canvas.current?.clear();
            }}
          >
            {t('prescriptions.handwriting.add_page')}
          </Button>
        ) : null}
        <Box sx={{ flexGrow: 1 }} />
        <ToggleButtonGroup size="small" exclusive value={tool} onChange={(_, next: Tool | null) => next !== null && setTool(next)}>
          <ToggleButton value="pen">{t('prescriptions.drawing.pen')}</ToggleButton>
          <ToggleButton value="eraser">{t('prescriptions.drawing.eraser')}</ToggleButton>
        </ToggleButtonGroup>
        <Tooltip title={t('prescriptions.drawing.undo')}>
          <IconButton size="small" onClick={() => canvas.current?.undo()} aria-label={t('prescriptions.drawing.undo')}>
            <UndoIcon fontSize="inherit" />
          </IconButton>
        </Tooltip>
        <Tooltip title={t('prescriptions.drawing.redo')}>
          <IconButton size="small" onClick={() => canvas.current?.redo()} aria-label={t('prescriptions.drawing.redo')}>
            <RedoIcon fontSize="inherit" />
          </IconButton>
        </Tooltip>
        <Button size="small" variant="outlined" onClick={() => void upload()}>
          {t('common.actions.save')}
        </Button>
      </Stack>

      <Alert severity="info" variant="outlined" sx={{ py: 0, mb: 1 }}>
        <Typography variant="caption">{t('prescriptions.issue.handwriting_not_checked')}</Typography>
      </Alert>
      {error !== null ? (
        <Alert severity="error" sx={{ py: 0, mb: 1 }}>
          <Typography variant="caption">{error}</Typography>
        </Alert>
      ) : null}

      <StrokeCanvas
        key={page}
        ref={canvas}
        width={width}
        height={height}
        tool={tool}
        color="#111827"
        onPause={() => void upload()}
        ariaLabel={t('prescriptions.handwriting.canvas', { page })}
      />
    </Paper>
  );
}

export default HandwritingPanel;
