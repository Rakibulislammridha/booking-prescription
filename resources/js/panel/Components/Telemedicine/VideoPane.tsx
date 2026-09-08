// The doctor's video surface, in MUI. It is a sibling of the site's `VideoStage`, not an import of it: the panel
// has no Tailwind (resources/css/panel.css is MUI + fonts), so the site's utility classes would render as an
// unstyled block here. What the two surfaces DO share is the part that matters — the call machine, the
// pre-flight and the client in `@site/Pages/Telemedicine/core/**`, which carry no styling at all.
import { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Typography from '@mui/material/Typography';

export interface VideoPaneProps {
  local: MediaStream | null;
  remote: MediaStream | null;
  camEnabled: boolean;
  waitingLabel: string;
  frameRef?: (node: HTMLDivElement | null) => void;
}

export function VideoPane({ local, remote, camEnabled, waitingLabel, frameRef }: VideoPaneProps) {
  const { t } = useTranslation();
  const localRef = useRef<HTMLVideoElement | null>(null);
  const remoteRef = useRef<HTMLVideoElement | null>(null);

  useEffect(() => {
    if (localRef.current && localRef.current.srcObject !== local) localRef.current.srcObject = local;
  }, [local]);

  useEffect(() => {
    if (remoteRef.current && remoteRef.current.srcObject !== remote) remoteRef.current.srcObject = remote;
  }, [remote]);

  return (
    <Box
      data-testid="video-stage"
      sx={{ position: 'relative', width: '100%', aspectRatio: '4 / 3', overflow: 'hidden', borderRadius: 1, bgcolor: 'grey.900' }}
    >
      <Box ref={frameRef} sx={{ position: 'absolute', inset: 0 }} />
      {remote ? (
        <Box component="video" ref={remoteRef} autoPlay playsInline aria-label={t('telemedicine.call.remote_video')} sx={{ width: '100%', height: '100%', objectFit: 'cover' }} />
      ) : (
        <Box sx={{ position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', px: 2, textAlign: 'center' }}>
          <Typography variant="caption" sx={{ color: 'grey.300' }} data-testid="remote-placeholder">{waitingLabel}</Typography>
        </Box>
      )}
      <Box sx={{ position: 'absolute', bottom: 8, right: 8, width: 96, height: 72, borderRadius: 1, overflow: 'hidden', border: 2, borderColor: 'rgba(255,255,255,0.35)', bgcolor: 'grey.800' }}>
        <Box component="video" ref={localRef} autoPlay playsInline muted aria-label={t('telemedicine.call.local_video')} sx={{ width: '100%', height: '100%', objectFit: 'cover', opacity: camEnabled ? 1 : 0 }} />
        {camEnabled ? null : (
          <Typography variant="caption" sx={{ position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'grey.300', fontSize: 10 }}>
            {t('telemedicine.call.camera_off')}
          </Typography>
        )}
      </Box>
    </Box>
  );
}
