// MUI theme (docs/ARCHITECTURE.md §7.6). Components use theme tokens only (CONVENTIONS §7.3).
import { createTheme } from '@mui/material/styles';

export const PANEL_PRIMARY = '#0f766e';

export const theme = createTheme({
  typography: { fontFamily: '"Inter", "Noto Sans Bengali", system-ui, sans-serif', fontSize: 14 },
  palette: { primary: { main: PANEL_PRIMARY } },
  components: { MuiButton: { defaultProps: { disableElevation: true } } },
});
