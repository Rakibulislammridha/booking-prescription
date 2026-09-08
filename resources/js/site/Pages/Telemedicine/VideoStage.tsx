// The two video surfaces. Kept dumb on purpose: it attaches streams to <video> elements and nothing else, so the
// same component serves the patient's full-width stage and the doctor's 320 px rail.
import { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';

export interface VideoStageProps {
  local: MediaStream | null;
  remote: MediaStream | null;
  remotePresent: boolean;
  camEnabled: boolean;
  /** Jitsi renders into its own iframe; this node is where it mounts. */
  frameRef?: (node: HTMLDivElement | null) => void;
  compact?: boolean;
  waitingLabel: string;
}

export function VideoStage({ local, remote, remotePresent, camEnabled, frameRef, compact = false, waitingLabel }: VideoStageProps) {
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
    <div className={`relative w-full overflow-hidden rounded-lg bg-slate-900 ${compact ? 'aspect-[4/3]' : 'aspect-video'}`} data-testid="video-stage">
      <div ref={frameRef} className="absolute inset-0" />
      {remote ? (
        <video ref={remoteRef} autoPlay playsInline className="h-full w-full object-cover" aria-label={t('telemedicine.call.remote_video')} />
      ) : (
        <div className="absolute inset-0 flex items-center justify-center px-4 text-center text-sm text-slate-300" data-testid="remote-placeholder">
          {remotePresent ? t('telemedicine.call.remote_no_video') : waitingLabel}
        </div>
      )}
      <div className={`absolute overflow-hidden rounded border-2 border-white/30 bg-slate-800 ${compact ? 'bottom-2 right-2 h-16 w-24' : 'bottom-3 right-3 h-24 w-32 sm:h-32 sm:w-44'}`}>
        <video ref={localRef} autoPlay playsInline muted className={`h-full w-full object-cover ${camEnabled ? '' : 'opacity-0'}`} aria-label={t('telemedicine.call.local_video')} />
        {camEnabled ? null : (
          <span className="absolute inset-0 flex items-center justify-center text-[11px] text-slate-300">{t('telemedicine.call.camera_off')}</span>
        )}
      </div>
    </div>
  );
}
