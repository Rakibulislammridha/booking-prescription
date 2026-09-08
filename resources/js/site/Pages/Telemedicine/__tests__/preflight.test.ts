// The device pre-flight, exercised against a fake mediaDevices — the suite never touches a real camera.
import { describe, expect, it } from 'vitest';
import { classifyMediaError, runPreflight, statusMessageKey, stopStream, type PreflightStatus } from '../core/preflight';

function track(kind: 'audio' | 'video', label = ''): MediaStreamTrack {
  return { kind, label, enabled: true, stop: () => undefined } as unknown as MediaStreamTrack;
}

function stream(kinds: Array<'audio' | 'video'>, labels: Record<string, string> = {}): MediaStream {
  const tracks = kinds.map((k) => track(k, labels[k] ?? ''));
  return {
    getTracks: () => tracks,
    getAudioTracks: () => tracks.filter((t) => t.kind === 'audio'),
    getVideoTracks: () => tracks.filter((t) => t.kind === 'video'),
  } as unknown as MediaStream;
}

function media(handler: (constraints: MediaStreamConstraints) => Promise<MediaStream>) {
  return { getUserMedia: handler };
}

function domError(name: string): Error {
  const error = new Error(name);
  error.name = name;
  return error;
}

describe('classifyMediaError', () => {
  const cases: Array<[string, PreflightStatus]> = [
    ['NotAllowedError', 'denied'],
    ['PermissionDeniedError', 'denied'],
    ['SecurityError', 'denied'],
    ['NotFoundError', 'no-devices'],
    ['DevicesNotFoundError', 'no-devices'],
    ['OverconstrainedError', 'no-devices'],
    ['NotReadableError', 'in-use'],
    ['TrackStartError', 'in-use'],
    ['AbortError', 'in-use'],
    ['SomethingElse', 'error'],
  ];

  it.each(cases)('maps %s to %s', (name, expected) => {
    expect(classifyMediaError(domError(name))).toBe(expected);
  });

  it('does not throw on a non-error', () => {
    expect(classifyMediaError(null)).toBe('error');
    expect(classifyMediaError('nope')).toBe('error');
  });
});

describe('runPreflight', () => {
  it('reports ready with both device labels when everything is granted', async () => {
    const report = await runPreflight({ media: media(async () => stream(['audio', 'video'], { audio: 'Built-in mic', video: 'HD Webcam' })) });

    expect(report.status).toBe('ready');
    expect(report.canJoin).toBe(true);
    expect(report.hasCamera).toBe(true);
    expect(report.hasMicrophone).toBe(true);
    expect(report.microphoneLabel).toBe('Built-in mic');
    expect(report.cameraLabel).toBe('HD Webcam');
    expect(report.messageKey).toBe('telemedicine.preflight.ready');
  });

  it('falls back to audio only when the camera is missing, and still allows joining', async () => {
    let attempt = 0;
    const report = await runPreflight({
      media: media(async (constraints) => {
        attempt += 1;
        if (constraints.video) throw domError('NotFoundError');
        return stream(['audio']);
      }),
    });

    expect(attempt).toBe(2);
    expect(report.status).toBe('no-camera');
    expect(report.canJoin).toBe(true);
    expect(report.hasCamera).toBe(false);
    expect(report.hasMicrophone).toBe(true);
  });

  it('does NOT retry when permission was refused — asking twice trains people to click block', async () => {
    let attempt = 0;
    const report = await runPreflight({
      media: media(async () => { attempt += 1; throw domError('NotAllowedError'); }),
    });

    expect(attempt).toBe(1);
    expect(report.status).toBe('denied');
    expect(report.canJoin).toBe(false);
    expect(report.messageKey).toBe('telemedicine.preflight.denied');
  });

  it('reports in-use when another application holds the camera', async () => {
    const report = await runPreflight({
      media: media(async (constraints) => { throw domError(constraints.video ? 'NotReadableError' : 'NotReadableError'); }),
    });

    expect(report.status).toBe('in-use');
    expect(report.canJoin).toBe(false);
  });

  it('reports no-devices when a granted stream carries no tracks at all', async () => {
    const report = await runPreflight({ media: media(async () => stream([])) });

    expect(report.status).toBe('no-devices');
    expect(report.canJoin).toBe(false);
  });

  it('refuses an insecure context before it asks for anything', async () => {
    let asked = false;
    const report = await runPreflight({ media: media(async () => { asked = true; return stream(['audio']); }), secureContext: false });

    expect(asked).toBe(false);
    expect(report.status).toBe('insecure');
  });

  it('reports unsupported when the browser has no mediaDevices', async () => {
    const report = await runPreflight({ media: null });

    expect(report.status).toBe('unsupported');
    expect(report.canJoin).toBe(false);
  });

  it('asks for audio only when video was not requested', async () => {
    let seen: MediaStreamConstraints | null = null;
    await runPreflight({ video: false, media: media(async (c) => { seen = c; return stream(['audio']); }) });

    expect(seen).toEqual({ audio: true, video: false });
  });
});

describe('helpers', () => {
  it('derives i18n keys that exist in the telemedicine.preflight block', () => {
    expect(statusMessageKey('no-camera')).toBe('telemedicine.preflight.no_camera');
    expect(statusMessageKey('in-use')).toBe('telemedicine.preflight.in_use');
    expect(statusMessageKey('ready')).toBe('telemedicine.preflight.ready');
  });

  it('stops every track and tolerates null', () => {
    const stopped: string[] = [];
    const s = { getTracks: () => [{ kind: 'audio', stop: () => stopped.push('audio') }, { kind: 'video', stop: () => stopped.push('video') }] } as unknown as MediaStream;

    stopStream(s);
    stopStream(null);

    expect(stopped).toEqual(['audio', 'video']);
  });
});
