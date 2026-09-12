// The tracing underlay's two controls: is the sample shown, and how strongly.
//
// They are their own component for one reason — they are the only part of the sample card that is pure state,
// so they can be tested against the live preview without an Inertia router. Neither value is pad data: the
// underlay is a viewing aid and is never saved, never snapshotted and never printed.
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import FormControlLabel from '@mui/material/FormControlLabel';
import Slider from '@mui/material/Slider';
import Switch from '@mui/material/Switch';
import Typography from '@mui/material/Typography';

interface Props {
  shown: boolean;
  opacity: number;
  isPdf: boolean;
  onShown: (shown: boolean) => void;
  onOpacity: (opacity: number) => void;
}

export function PadUnderlayControls({ shown, opacity, isPdf, onShown, onOpacity }: Props) {
  const { t } = useTranslation();

  return (
    <Box sx={{ mt: 1 }}>
      <FormControlLabel
        control={<Switch size="small" checked={shown} onChange={(e) => onShown(e.target.checked)} />}
        label={t('clinic.pad.underlay.show')}
      />
      <Box>
        <Typography variant="caption" color="text.secondary" id="pad-underlay-opacity">{t('clinic.pad.underlay.opacity')}</Typography>
        <Slider
          size="small"
          value={opacity}
          min={0.05}
          max={1}
          step={0.05}
          disabled={!shown}
          valueLabelDisplay="auto"
          valueLabelFormat={(value: number) => `${Math.round(value * 100)}%`}
          slotProps={{ input: { 'aria-labelledby': 'pad-underlay-opacity' } }}
          onChange={(_event: Event, value: number | number[]) => onOpacity(Array.isArray(value) ? (value[0] ?? opacity) : value)}
        />
      </Box>
      {isPdf ? <Typography variant="caption" color="text.secondary">{t('clinic.pad.underlay.pdf_note')}</Typography> : null}
    </Box>
  );
}
