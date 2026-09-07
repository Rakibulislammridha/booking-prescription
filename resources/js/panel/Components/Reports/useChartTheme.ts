// Chart colours come from the MUI palette, never from hard-coded hexes (CONVENTIONS §7.3). The panel ships a
// light theme today; deriving every series from the palette means a future dark mode recolours the charts with
// it instead of leaving dark-on-dark axes behind.
import { useTheme } from '@mui/material/styles';

export interface ChartTheme {
  series: string[];
  grid: string;
  axis: string;
  tooltip: { backgroundColor: string; border: string; borderRadius: number; color: string; fontSize: number };
  good: string;
  bad: string;
  warn: string;
  heat: (ratio: number) => string;
}

export function useChartTheme(): ChartTheme {
  const theme = useTheme();
  const { palette } = theme;

  return {
    series: [
      palette.primary.main,
      palette.secondary.main,
      palette.warning.main,
      palette.info.main,
      palette.success.main,
      palette.error.main,
    ],
    grid: palette.divider,
    axis: palette.text.secondary,
    tooltip: {
      backgroundColor: palette.background.paper,
      border: `1px solid ${palette.divider}`,
      borderRadius: 6,
      color: palette.text.primary,
      fontSize: 12,
    },
    good: palette.success.main,
    bad: palette.error.main,
    warn: palette.warning.main,
    // A single-hue ramp from the paper colour to the primary: readable in either theme, and colour-blind safe
    // because it varies in lightness rather than in hue.
    heat: (ratio: number) => {
      const clamped = Math.max(0, Math.min(1, ratio));
      return clamped === 0 ? palette.action.hover : theme.palette.augmentColor({ color: { main: palette.primary.main } }).main + Math.round(38 + clamped * 217).toString(16).padStart(2, '0');
    },
  };
}
