// The letterhead editor — the left half of the pad designer (BRIEF §5.A).
//
// Until this screen existed the letterhead was one free-HTML textarea, which is why every clinic printed the same
// generic header: nobody builds a three-column footer in a textarea. Here a doctor builds the pad they actually
// print — their name in the clinic's accent colour, the qualification lines under it, a rule, then a footer of up
// to three columns (logo and address, chamber times, the number a patient rings for a serial) — and every edit
// lands in the preview on the right in the same tick, because this component owns no state of its own: the page's
// `useForm` data IS the object, mutated immutably through `@panel/lib/clinic/letterhead`.
//
// Two deliberate choices. Reordering is up/down BUTTONS, not drag and drop: dnd-kit would be ~12 KB gzip on a
// route that also carries the preview, and a pad has four header lines, not forty. And every sub-component is
// declared at module scope — an editor defined inside the render remounts its TextField on every keystroke and
// loses the caret, which is a bug this codebase has already paid for once.
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Divider from '@mui/material/Divider';
import FormControlLabel from '@mui/material/FormControlLabel';
import Grid from '@mui/material/Grid';
import IconButton from '@mui/material/IconButton';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import AddIcon from '@mui/icons-material/Add';
import ArrowDownIcon from '@mui/icons-material/ArrowDownward';
import ArrowUpIcon from '@mui/icons-material/ArrowUpward';
import DeleteIcon from '@mui/icons-material/DeleteOutlined';
import RestoreIcon from '@mui/icons-material/RestartAlt';
import {
  LETTERHEAD_ALIGNS, LETTERHEAD_COLORS, MAX_COLUMNS, MAX_LINES, MAX_TEXT, SIZE_MAX, SIZE_MIN,
  blankColumn, blankLine, clampSize, hexOr, moveItem, paletteOf, removeAt, replaceAt,
} from '@panel/lib/clinic/letterhead';
import type { Letterhead, LetterheadAlign, LetterheadColor, LetterheadColumn, LetterheadLine } from '@shared/types/models';

interface Props {
  value: Letterhead;
  onChange: (next: Letterhead) => void;
  /** Loads `PadLetterhead::defaults()` — the doctor's own name, degrees, BMDC number and clinic. */
  onReset: () => void;
  disabled?: boolean;
}

export function PadLetterheadEditor({ value, onChange, onReset, disabled = false }: Props) {
  const { t } = useTranslation();
  const palette = paletteOf(value);

  const setHeader = (patch: Partial<Letterhead['header']>): void => onChange({ ...value, header: { ...value.header, ...patch } });
  const setFooter = (patch: Partial<Letterhead['footer']>): void => onChange({ ...value, footer: { ...value.footer, ...patch } });
  const setColumn = (index: number, column: LetterheadColumn): void => setFooter({ columns: replaceAt(value.footer.columns, index, column) });

  return (
    <Card>
      <CardContent>
        <Stack direction="row" spacing={1} sx={{ alignItems: 'center', mb: 1 }}>
          <Typography variant="subtitle2" sx={{ flexGrow: 1 }}>{t('clinic.pad.letterhead.title')}</Typography>
          <Button size="small" startIcon={<RestoreIcon />} disabled={disabled} onClick={onReset}>
            {t('clinic.pad.letterhead.reset')}
          </Button>
        </Stack>
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 2 }}>{t('clinic.pad.letterhead.help')}</Typography>

        <Grid container spacing={2}>
          <Grid size={{ xs: 12, sm: 4 }}>
            <ColorField label={t('clinic.pad.letterhead.accent_color')} value={value.accent_color} fallback={palette.accent} disabled={disabled}
              onChange={(hex) => onChange({ ...value, accent_color: hex })} />
          </Grid>
          <Grid size={{ xs: 12, sm: 4 }}>
            <ColorField label={t('clinic.pad.letterhead.text_color')} value={value.text_color} fallback={palette.text} disabled={disabled}
              onChange={(hex) => onChange({ ...value, text_color: hex })} />
          </Grid>
          <Grid size={{ xs: 12, sm: 4 }}>
            <ColorField label={t('clinic.pad.letterhead.muted_color')} value={value.muted_color} fallback={palette.muted} disabled={disabled}
              onChange={(hex) => onChange({ ...value, muted_color: hex })} />
          </Grid>
        </Grid>

        <Divider sx={{ my: 2 }} />

        <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
          <Typography variant="subtitle2" sx={{ flexGrow: 1 }}>{t('clinic.pad.letterhead.header')}</Typography>
          <TextField
            select size="small" label={t('clinic.pad.letterhead.header_align')} value={value.header.align} disabled={disabled}
            sx={{ minWidth: 140 }}
            onChange={(e) => setHeader({ align: e.target.value as Letterhead['header']['align'] })}
          >
            {(['left', 'center', 'split'] as const).map((align) => (
              <MenuItem key={align} value={align}>{t(`clinic.pad.letterhead.align_${align}`)}</MenuItem>
            ))}
          </TextField>
        </Stack>
        {value.header.align === 'split' ? (
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: .5 }}>{t('clinic.pad.letterhead.split_help')}</Typography>
        ) : null}
        <FormControlLabel
          sx={{ mt: .5 }}
          control={<Switch size="small" checked={value.header.rule} disabled={disabled} onChange={(e) => setHeader({ rule: e.target.checked })} />}
          label={t('clinic.pad.letterhead.header_rule')}
        />

        <Stack spacing={1} sx={{ mt: 1 }}>
          {value.header.lines.length === 0 ? (
            <Typography variant="caption" color="text.secondary">{t('clinic.pad.letterhead.no_lines')}</Typography>
          ) : null}
          {value.header.lines.map((line, index) => (
            <LineEditor
              key={index}
              line={line}
              index={index}
              count={value.header.lines.length}
              palette={palette}
              disabled={disabled}
              testId={`letterhead-header-line-${index}`}
              onChange={(next) => setHeader({ lines: replaceAt(value.header.lines, index, next) })}
              onMove={(delta) => setHeader({ lines: moveItem(value.header.lines, index, delta) })}
              onRemove={() => setHeader({ lines: removeAt(value.header.lines, index) })}
            />
          ))}
          <Box>
            <Button size="small" startIcon={<AddIcon />} disabled={disabled || value.header.lines.length >= MAX_LINES}
              onClick={() => setHeader({ lines: [...value.header.lines, blankLine()] })}>
              {t('clinic.pad.letterhead.add_line')}
            </Button>
          </Box>
        </Stack>

        <Divider sx={{ my: 2 }} />

        <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
          <Typography variant="subtitle2" sx={{ flexGrow: 1 }}>{t('clinic.pad.letterhead.footer')}</Typography>
          <Button size="small" startIcon={<AddIcon />} disabled={disabled || value.footer.columns.length >= MAX_COLUMNS}
            onClick={() => setFooter({ columns: [...value.footer.columns, blankColumn(value.footer.columns.length)] })}>
            {t('clinic.pad.letterhead.add_column')}
          </Button>
        </Stack>
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('clinic.pad.letterhead.footer_help')}</Typography>
        {value.footer.columns.length === 0 ? (
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('clinic.pad.letterhead.no_columns')}</Typography>
        ) : null}
        <FormControlLabel
          control={<Switch size="small" checked={value.footer.rule} disabled={disabled} onChange={(e) => setFooter({ rule: e.target.checked })} />}
          label={t('clinic.pad.letterhead.footer_rule')}
        />

        <Stack spacing={2} sx={{ mt: 1 }}>
          {value.footer.columns.map((column, index) => (
            <Box key={index} data-testid={`letterhead-column-${index}`} sx={{ border: 1, borderColor: 'divider', borderRadius: 1, p: 1.5 }}>
              <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
                <Typography variant="body2" sx={{ flexGrow: 1, fontWeight: 600 }}>
                  {t('clinic.pad.letterhead.column_n', { n: index + 1 })}
                </Typography>
                <TextField
                  select size="small" label={t('clinic.pad.letterhead.column_align')} value={column.align} disabled={disabled}
                  sx={{ minWidth: 120 }}
                  onChange={(e) => setColumn(index, { ...column, align: e.target.value as LetterheadAlign })}
                >
                  {LETTERHEAD_ALIGNS.map((align) => (
                    <MenuItem key={align} value={align}>{t(`clinic.pad.letterhead.align_${align}`)}</MenuItem>
                  ))}
                </TextField>
                <IconButton size="small" aria-label={t('clinic.pad.letterhead.move_column_left')} disabled={disabled || index === 0}
                  onClick={() => setFooter({ columns: moveItem(value.footer.columns, index, -1) })}>
                  <ArrowUpIcon fontSize="small" sx={{ transform: 'rotate(-90deg)' }} />
                </IconButton>
                <IconButton size="small" aria-label={t('clinic.pad.letterhead.move_column_right')} disabled={disabled || index === value.footer.columns.length - 1}
                  onClick={() => setFooter({ columns: moveItem(value.footer.columns, index, 1) })}>
                  <ArrowDownIcon fontSize="small" sx={{ transform: 'rotate(-90deg)' }} />
                </IconButton>
                <IconButton size="small" aria-label={t('clinic.pad.letterhead.remove_column')} disabled={disabled}
                  onClick={() => setFooter({ columns: removeAt(value.footer.columns, index) })}>
                  <DeleteIcon fontSize="small" />
                </IconButton>
              </Stack>
              <FormControlLabel
                control={<Switch size="small" checked={column.logo} disabled={disabled} onChange={(e) => setColumn(index, { ...column, logo: e.target.checked })} />}
                label={t('clinic.pad.letterhead.column_logo')}
              />
              <Stack spacing={1} sx={{ mt: .5 }}>
                {column.lines.map((line, lineIndex) => (
                  <LineEditor
                    key={lineIndex}
                    line={line}
                    index={lineIndex}
                    count={column.lines.length}
                    palette={palette}
                    disabled={disabled}
                    testId={`letterhead-column-${index}-line-${lineIndex}`}
                    onChange={(next) => setColumn(index, { ...column, lines: replaceAt(column.lines, lineIndex, next) })}
                    onMove={(delta) => setColumn(index, { ...column, lines: moveItem(column.lines, lineIndex, delta) })}
                    onRemove={() => setColumn(index, { ...column, lines: removeAt(column.lines, lineIndex) })}
                  />
                ))}
                <Box>
                  <Button size="small" startIcon={<AddIcon />} disabled={disabled || column.lines.length >= MAX_LINES}
                    onClick={() => setColumn(index, { ...column, lines: [...column.lines, blankLine()] })}>
                    {t('clinic.pad.letterhead.add_line')}
                  </Button>
                </Box>
              </Stack>
            </Box>
          ))}
        </Stack>
      </CardContent>
    </Card>
  );
}

interface LineEditorProps {
  line: LetterheadLine;
  index: number;
  count: number;
  palette: Record<LetterheadColor, string>;
  disabled: boolean;
  testId: string;
  onChange: (next: LetterheadLine) => void;
  onMove: (delta: number) => void;
  onRemove: () => void;
}

/** One printed line: what it says, in which of the pad's three colours, how big, and where it sits. */
function LineEditor({ line, index, count, palette, disabled, testId, onChange, onMove, onRemove }: LineEditorProps) {
  const { t } = useTranslation();

  return (
    <Box data-testid={testId} sx={{ borderLeft: 3, borderColor: palette[line.color], pl: 1.25, py: .5 }}>
      <Stack direction="row" spacing={.5} sx={{ alignItems: 'flex-start' }}>
        <Stack spacing={1} sx={{ flexGrow: 1, minWidth: 0 }}>
          <TextField
            size="small" fullWidth label={t('clinic.pad.letterhead.line_text')} value={line.text} disabled={disabled}
            onChange={(e) => onChange({ ...line, text: e.target.value })}
            slotProps={{ htmlInput: { maxLength: MAX_TEXT, 'aria-label': t('clinic.pad.letterhead.line_text') } }}
          />
          <TextField
            size="small" fullWidth label={t('clinic.pad.letterhead.line_text_bn')} value={line.text_bn ?? ''} disabled={disabled}
            onChange={(e) => onChange({ ...line, text_bn: e.target.value === '' ? null : e.target.value })}
            slotProps={{ htmlInput: { lang: 'bn', maxLength: MAX_TEXT, 'aria-label': t('clinic.pad.letterhead.line_text_bn') } }}
          />
        </Stack>
        <Stack>
          <IconButton size="small" aria-label={t('clinic.pad.move_up')} disabled={disabled || index === 0} onClick={() => onMove(-1)}>
            <ArrowUpIcon fontSize="small" />
          </IconButton>
          <IconButton size="small" aria-label={t('clinic.pad.move_down')} disabled={disabled || index === count - 1} onClick={() => onMove(1)}>
            <ArrowDownIcon fontSize="small" />
          </IconButton>
          <IconButton size="small" aria-label={t('clinic.pad.letterhead.delete_line')} disabled={disabled} onClick={onRemove}>
            <DeleteIcon fontSize="small" />
          </IconButton>
        </Stack>
      </Stack>

      <Grid container spacing={1} sx={{ mt: .25 }}>
        <Grid size={{ xs: 6, sm: 4 }}>
          <TextField
            select fullWidth size="small" label={t('clinic.pad.letterhead.color')} value={line.color} disabled={disabled}
            onChange={(e) => onChange({ ...line, color: e.target.value as LetterheadColor })}
          >
            {LETTERHEAD_COLORS.map((color) => (
              <MenuItem key={color} value={color}>{t(`clinic.pad.letterhead.color_${color}`)}</MenuItem>
            ))}
          </TextField>
        </Grid>
        <Grid size={{ xs: 6, sm: 4 }}>
          <TextField
            type="number" fullWidth size="small" label={t('clinic.pad.letterhead.size')} value={line.size} disabled={disabled}
            onChange={(e) => onChange({ ...line, size: clampSize(Number(e.target.value)) })}
            slotProps={{ htmlInput: { min: SIZE_MIN, max: SIZE_MAX, step: 0.05, inputMode: 'decimal' } }}
          />
        </Grid>
        <Grid size={{ xs: 12, sm: 4 }}>
          <TextField
            select fullWidth size="small" label={t('clinic.pad.letterhead.line_align')} value={line.align ?? ''} disabled={disabled}
            onChange={(e) => onChange({ ...line, align: e.target.value === '' ? null : (e.target.value as LetterheadAlign) })}
          >
            <MenuItem value="">{t('clinic.pad.letterhead.align_inherit')}</MenuItem>
            {LETTERHEAD_ALIGNS.map((align) => (
              <MenuItem key={align} value={align}>{t(`clinic.pad.letterhead.align_${align}`)}</MenuItem>
            ))}
          </TextField>
        </Grid>
      </Grid>

      <Stack direction="row" spacing={2} sx={{ mt: .25 }}>
        <FormControlLabel
          control={<Switch size="small" checked={line.weight === 'bold'} disabled={disabled}
            onChange={(e) => onChange({ ...line, weight: e.target.checked ? 'bold' : 'normal' })} />}
          label={<Typography variant="caption">{t('clinic.pad.letterhead.bold')}</Typography>}
        />
        <FormControlLabel
          control={<Switch size="small" checked={line.transform === 'uppercase'} disabled={disabled}
            onChange={(e) => onChange({ ...line, transform: e.target.checked ? 'uppercase' : 'none' })} />}
          label={<Typography variant="caption">{t('clinic.pad.letterhead.uppercase')}</Typography>}
        />
      </Stack>
    </Box>
  );
}

interface ColorFieldProps {
  label: string;
  value: string;
  fallback: string;
  disabled: boolean;
  onChange: (hex: string) => void;
}

/**
 * A colour the print CSS can use, which means `#RRGGBB` and nothing else. The swatch is the browser's own picker
 * (no dependency, and it is the control every doctor already knows); the text box is there because a clinic's
 * brand colour usually arrives as a hex string in an email.
 */
function ColorField({ label, value, fallback, disabled, onChange }: ColorFieldProps) {
  return (
    <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
      <Box
        component="input"
        type="color"
        aria-label={label}
        value={hexOr(value, fallback)}
        disabled={disabled}
        onChange={(e: React.ChangeEvent<HTMLInputElement>) => onChange(e.target.value.toUpperCase())}
        sx={{ width: 40, height: 40, p: 0, border: 1, borderColor: 'divider', borderRadius: 1, bgcolor: 'transparent', cursor: 'pointer' }}
      />
      <TextField
        size="small" fullWidth label={label} value={value} disabled={disabled}
        onChange={(e) => onChange(e.target.value)}
        slotProps={{ htmlInput: { maxLength: 7, spellCheck: false } }}
      />
    </Stack>
  );
}
