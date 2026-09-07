// Draw / annotate (§4.11): dental, ortho, eye and body diagrams the doctor marks up with a stylus. The strokes are
// the record (vector, re-rendered at print size); the PNG is only the timeline thumbnail and the verification page.
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import IconButton from '@mui/material/IconButton';
import MenuItem from '@mui/material/MenuItem';
import Select from '@mui/material/Select';
import Stack from '@mui/material/Stack';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import Tooltip from '@mui/material/Tooltip';
import RedoIcon from '@mui/icons-material/Redo';
import UndoIcon from '@mui/icons-material/Undo';
import DeleteIcon from '@mui/icons-material/DeleteOutlined';
import type { DrawingJson } from '@shared/types/models';
import { saveDrawing } from '@panel/api/prescription';
import type { DrawingTemplate } from '@panel/lib/prescription/drawingBackgrounds';
import { StrokeCanvas, type StrokeCanvasHandle, type Tool } from './StrokeCanvas';

const COLOURS = ['#111827', '#dc2626', '#2563eb', '#16a34a'];
const CANVAS = { w: 900, h: 620 };

export interface DrawingDialogProps {
  open: boolean;
  prescriptionId: string;
  templates: string[];
  initial: DrawingJson | null;
  onClose: () => void;
  onSaved: (json: DrawingJson) => void;
}

export function DrawingDialog({ open, prescriptionId, templates, initial, onClose, onSaved }: DrawingDialogProps) {
  const { t } = useTranslation();
  const canvas = useRef<StrokeCanvasHandle | null>(null);
  const [template, setTemplate] = useState<DrawingTemplate>((initial?.canvas.template as DrawingTemplate | undefined) ?? 'blank');
  const [tool, setTool] = useState<Tool>('pen');
  const [colour, setColour] = useState(COLOURS[0] as string);
  const [busy, setBusy] = useState(false);

  const save = async (): Promise<void> => {
    if (canvas.current === null) return;
    setBusy(true);
    try {
      const json: DrawingJson = { canvas: { w: CANVAS.w, h: CANVAS.h, template }, strokes: canvas.current.strokes() };
      const png = await canvas.current.toPng(2);
      const result = await saveDrawing(prescriptionId, json, png);
      onSaved(result.drawing_json ?? json);
      onClose();
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} maxWidth={false}>
      <DialogTitle sx={{ py: 1 }}>{t('prescriptions.drawing.title')}</DialogTitle>
      <DialogContent>
        <Stack direction="row" spacing={1} sx={{ mb: 1, alignItems: 'center', flexWrap: 'wrap' }}>
          <Select size="small" value={template} onChange={(e) => setTemplate(e.target.value as DrawingTemplate)} sx={{ minWidth: 170 }} inputProps={{ 'aria-label': t('prescriptions.drawing.template') }}>
            {templates.map((name) => (
              <MenuItem key={name} value={name}>
                {t(`prescriptions.drawing.templates.${name}`)}
              </MenuItem>
            ))}
          </Select>
          <ToggleButtonGroup size="small" exclusive value={tool} onChange={(_, next: Tool | null) => next !== null && setTool(next)}>
            <ToggleButton value="pen">{t('prescriptions.drawing.pen')}</ToggleButton>
            <ToggleButton value="marker">{t('prescriptions.drawing.marker')}</ToggleButton>
            <ToggleButton value="eraser">{t('prescriptions.drawing.eraser')}</ToggleButton>
          </ToggleButtonGroup>
          <Stack direction="row" spacing={0.5}>
            {COLOURS.map((c) => (
              <Box
                key={c}
                role="button"
                aria-label={c}
                onClick={() => setColour(c)}
                sx={{ width: 22, height: 22, borderRadius: '50%', bgcolor: c, cursor: 'pointer', outline: colour === c ? '2px solid' : 'none', outlineColor: 'primary.main', outlineOffset: 2 }}
              />
            ))}
          </Stack>
          <Box sx={{ flexGrow: 1 }} />
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
          <Tooltip title={t('prescriptions.drawing.clear')}>
            <IconButton size="small" onClick={() => canvas.current?.clear()} aria-label={t('prescriptions.drawing.clear')}>
              <DeleteIcon fontSize="inherit" />
            </IconButton>
          </Tooltip>
        </Stack>
        <StrokeCanvas ref={canvas} width={CANVAS.w} height={CANVAS.h} template={template} tool={tool} color={colour} initialStrokes={initial?.strokes ?? []} ariaLabel={t('prescriptions.drawing.canvas')} />
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>{t('common.actions.cancel')}</Button>
        <Button variant="contained" disabled={busy} onClick={() => void save()}>
          {t('common.actions.save')}
        </Button>
      </DialogActions>
    </Dialog>
  );
}

export default DrawingDialog;
