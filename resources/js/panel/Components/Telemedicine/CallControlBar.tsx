// Mute / camera / leave + the connection-quality dot, in MUI. Presentational only: every decision comes from the
// shared `callMachine` reducer, so this bar and the patient's Tailwind one can never disagree about the state.
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import MicIcon from '@mui/icons-material/Mic';
import MicOffIcon from '@mui/icons-material/MicOff';
import VideocamIcon from '@mui/icons-material/Videocam';
import VideocamOffIcon from '@mui/icons-material/VideocamOff';
import type { CallQuality, CallState } from '@site/Pages/Telemedicine/core/callMachine';

const DOT: Record<CallQuality, string> = {
  unknown: 'grey.400',
  good: 'success.main',
  fair: 'warning.main',
  poor: 'error.main',
};

export interface CallControlBarProps {
  state: CallState;
  hasCamera: boolean;
  onToggleMic(): void;
  onToggleCam(): void;
  onLeave(): void;
}

export function CallControlBar({ state, hasCamera, onToggleMic, onToggleCam, onLeave }: CallControlBarProps) {
  const { t } = useTranslation();

  return (
    <Stack spacing={1} data-testid="call-controls">
      <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap', gap: 1 }}>
        <Button
          size="small"
          variant={state.micEnabled ? 'outlined' : 'contained'}
          color={state.micEnabled ? 'inherit' : 'error'}
          startIcon={state.micEnabled ? <MicIcon fontSize="small" /> : <MicOffIcon fontSize="small" />}
          onClick={onToggleMic}
          aria-pressed={!state.micEnabled}
          data-testid="toggle-mic"
        >
          {state.micEnabled ? t('telemedicine.call.mute') : t('telemedicine.call.unmute')}
        </Button>
        {hasCamera ? (
          <Button
            size="small"
            variant={state.camEnabled ? 'outlined' : 'contained'}
            color={state.camEnabled ? 'inherit' : 'error'}
            startIcon={state.camEnabled ? <VideocamIcon fontSize="small" /> : <VideocamOffIcon fontSize="small" />}
            onClick={onToggleCam}
            aria-pressed={!state.camEnabled}
            data-testid="toggle-cam"
          >
            {state.camEnabled ? t('telemedicine.call.camera_off_action') : t('telemedicine.call.camera_on_action')}
          </Button>
        ) : null}
        <Button size="small" variant="outlined" color="error" onClick={onLeave} data-testid="leave-call">
          {t('telemedicine.console.leave')}
        </Button>
      </Stack>
      <Stack direction="row" spacing={0.75} sx={{ alignItems: 'center' }} data-testid="call-quality">
        <Box sx={{ width: 9, height: 9, borderRadius: '50%', bgcolor: DOT[state.quality] }} aria-hidden />
        <Typography variant="caption" color="text.secondary">{t(`telemedicine.call.quality.${state.quality}`)}</Typography>
        {state.phase === 'reconnecting' ? <Typography variant="caption" color="warning.main">· {t('telemedicine.call.reconnecting')}</Typography> : null}
      </Stack>
    </Stack>
  );
}
