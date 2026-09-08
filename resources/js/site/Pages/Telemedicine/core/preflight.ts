// Device pre-flight for a video consultation (BRIEF §5.K waiting room). Framework-free on purpose: the panel's
// in-call rail imports the very same module (`@site/Pages/Telemedicine/core/preflight`), so a doctor and a
// patient are told the same thing about the same browser, and one Vitest suite covers both.
//
// The failure modes are enumerated rather than collapsed into "camera error", because each one has a different
// answer for the person reading it: a blocked permission needs the address-bar padlock, a missing camera needs a
// different device, a camera held by another app needs that app closed, and an http:// page needs https.

export type PreflightStatus =
  | 'idle'
  | 'checking'
  | 'ready'
  | 'denied'          // the user (or a policy) refused the permission
  | 'no-camera'       // microphone yes, camera no — audio-only is still a consultation
  | 'no-devices'      // neither: nothing to consult with
  | 'in-use'          // another application holds the device
  | 'insecure'        // getUserMedia needs a secure context
  | 'unsupported'     // the browser has no mediaDevices at all
  | 'error';

export interface PreflightReport {
  status: PreflightStatus;
  hasCamera: boolean;
  hasMicrophone: boolean;
  cameraLabel: string | null;
  microphoneLabel: string | null;
  /** i18n key under telemedicine.preflight.* describing the outcome. */
  messageKey: string;
  /** Audio-only is enough to join; only `no-devices`, `denied` and the hard failures are blocking. */
  canJoin: boolean;
  stream: MediaStream | null;
}

export const IDLE_REPORT: PreflightReport = {
  status: 'idle',
  hasCamera: false,
  hasMicrophone: false,
  cameraLabel: null,
  microphoneLabel: null,
  messageKey: 'telemedicine.preflight.idle',
  canJoin: false,
  stream: null,
};

interface MediaLike {
  getUserMedia(constraints: MediaStreamConstraints): Promise<MediaStream>;
  enumerateDevices?(): Promise<MediaDeviceInfo[]>;
}

export interface PreflightOptions {
  media?: MediaLike | null;
  secureContext?: boolean;
  /** Ask for video as well as audio. A patient on a metered 2G connection may choose audio only. */
  video?: boolean;
}

/** DOMException names are the only stable part of the getUserMedia error contract; message text is not. */
export function classifyMediaError(error: unknown): PreflightStatus {
  const name = typeof error === 'object' && error !== null && 'name' in error ? String((error as { name: unknown }).name) : '';
  switch (name) {
    case 'NotAllowedError':
    case 'SecurityError':
    case 'PermissionDeniedError':
      return 'denied';
    case 'NotFoundError':
    case 'DevicesNotFoundError':
    case 'OverconstrainedError':
      return 'no-devices';
    case 'NotReadableError':
    case 'TrackStartError':
    case 'AbortError':
      return 'in-use';
    default:
      return 'error';
  }
}

export function statusMessageKey(status: PreflightStatus): string {
  return `telemedicine.preflight.${status.replace(/-/g, '_')}`;
}

function report(partial: Partial<PreflightReport> & { status: PreflightStatus }): PreflightReport {
  const status = partial.status;
  return {
    ...IDLE_REPORT,
    ...partial,
    messageKey: partial.messageKey ?? statusMessageKey(status),
    canJoin: status === 'ready' || status === 'no-camera',
  };
}

function trackLabel(stream: MediaStream, kind: 'audio' | 'video'): string | null {
  const track = (kind === 'audio' ? stream.getAudioTracks() : stream.getVideoTracks())[0];
  return track ? track.label || null : null;
}

/**
 * Asks for the devices, then reports what was actually granted. It deliberately RETRIES audio-only when video
 * fails: a patient with a broken webcam should still reach their doctor, and a hard failure there would be the
 * module's most common support call.
 */
export async function runPreflight(options: PreflightOptions = {}): Promise<PreflightReport> {
  const media = options.media ?? (typeof navigator !== 'undefined' ? (navigator.mediaDevices as MediaLike | undefined) ?? null : null);
  const secure = options.secureContext ?? (typeof window === 'undefined' ? true : window.isSecureContext !== false);
  const wantVideo = options.video ?? true;

  if (!media || typeof media.getUserMedia !== 'function') return report({ status: 'unsupported' });
  if (!secure) return report({ status: 'insecure' });

  try {
    const stream = await media.getUserMedia({ audio: true, video: wantVideo });
    const hasCamera = stream.getVideoTracks().length > 0;
    const hasMicrophone = stream.getAudioTracks().length > 0;
    if (!hasCamera && !hasMicrophone) {
      stopStream(stream);
      return report({ status: 'no-devices' });
    }
    return report({
      status: hasCamera ? 'ready' : 'no-camera',
      hasCamera,
      hasMicrophone,
      cameraLabel: trackLabel(stream, 'video'),
      microphoneLabel: trackLabel(stream, 'audio'),
      stream,
    });
  } catch (error) {
    const status = classifyMediaError(error);
    if (status !== 'denied' && wantVideo) {
      try {
        const audioOnly = await media.getUserMedia({ audio: true, video: false });
        return report({
          status: 'no-camera',
          hasCamera: false,
          hasMicrophone: audioOnly.getAudioTracks().length > 0,
          microphoneLabel: trackLabel(audioOnly, 'audio'),
          stream: audioOnly,
        });
      } catch {
        /* fall through to the original classification */
      }
    }
    return report({ status });
  }
}

export function stopStream(stream: MediaStream | null): void {
  stream?.getTracks().forEach((track) => track.stop());
}
