// The browser half of the provider-agnostic video layer. Same shape as the PHP `VideoProvider` contract, same
// three drivers, and — like the server — the null driver is a REAL implementation rather than a stub: it shows
// the local camera and reports its own connection state, which is what makes the pre-flight, the controls, the
// reconnect path and the headless walkthrough all exercisable with no video service in existence.
//
// Nothing here is imported by a page at module scope. `loadVideoClient()` is dynamic, so neither the site's
// first-load budget (95 KB gzip, REALTIME.md §8) nor the panel's initial chunk carries a video SDK, and a
// patient who never presses "Join" never downloads one.

export type VideoConnectionState = 'idle' | 'connecting' | 'connected' | 'reconnecting' | 'disconnected';

export interface VideoCredentials {
  provider: string;
  room: string;
  token: string;
  identity: string;
  /** null ⇒ no video service is configured; the client runs in local-preview mode. */
  server_url: string | null;
  join_url?: string | null;
  /** The provider's own participant number where it has one — Agora addresses users by uint32, not by name. */
  uid?: number | null;
}

export interface VideoClientHandlers {
  onState?(state: VideoConnectionState): void;
  onRemote?(present: boolean, stream: MediaStream | null): void;
  onQuality?(stats: { packetLossPct?: number; rttMs?: number; bitrateKbps?: number }): void;
  onError?(error: unknown): void;
}

export interface VideoClient {
  readonly kind: 'local' | 'jitsi' | 'livekit';
  connect(credentials: VideoCredentials, local: MediaStream | null): Promise<void>;
  setMicrophone(enabled: boolean): void;
  setCamera(enabled: boolean): void;
  localStream(): MediaStream | null;
  remoteStream(): MediaStream | null;
  disconnect(): Promise<void>;
}

/**
 * Local-preview client. It publishes nothing and receives nothing — there is no server to publish to — but it
 * owns the local tracks, honours the mute/camera toggles, and reports `connected`. Used whenever the server
 * minted a token with no `server_url` (the `null` driver), and as the fallback when a real SDK cannot be loaded
 * on a poor connection, which is strictly better than a black rectangle.
 */
export function createLocalVideoClient(handlers: VideoClientHandlers = {}): VideoClient {
  let stream: MediaStream | null = null;
  let state: VideoConnectionState = 'idle';

  const move = (next: VideoConnectionState): void => {
    state = next;
    handlers.onState?.(next);
  };

  return {
    kind: 'local',
    async connect(_credentials, local) {
      stream = local;
      move('connecting');
      move('connected');
      handlers.onRemote?.(false, null);
    },
    setMicrophone(enabled) {
      stream?.getAudioTracks().forEach((t) => { t.enabled = enabled; });
    },
    setCamera(enabled) {
      stream?.getVideoTracks().forEach((t) => { t.enabled = enabled; });
    },
    localStream() {
      return stream;
    },
    remoteStream() {
      return null;
    },
    async disconnect() {
      if (state !== 'disconnected') move('disconnected');
      stream = null;
    },
  };
}

/**
 * Jitsi Meet: the SDK is one script tag served by the clinic's own Jitsi host (`external_api.js`), so there is
 * no npm dependency and nothing to keep in sync with a package-lock. Loaded on demand, and if the host cannot
 * be reached the caller falls back to the local client rather than showing nothing.
 */
async function loadJitsi(domain: string): Promise<unknown> {
  const w = window as unknown as Record<string, unknown>;
  if (w.JitsiMeetExternalAPI) return w.JitsiMeetExternalAPI;
  await new Promise<void>((resolve, reject) => {
    const script = document.createElement('script');
    script.src = `https://${domain}/external_api.js`;
    script.async = true;
    script.onload = () => resolve();
    script.onerror = () => reject(new Error('jitsi_sdk_unavailable'));
    document.head.appendChild(script);
  });
  return (window as unknown as Record<string, unknown>).JitsiMeetExternalAPI ?? null;
}

interface JitsiApi {
  executeCommand(command: string, ...args: unknown[]): void;
  addListener(event: string, handler: (payload: unknown) => void): void;
  dispose(): void;
}

type JitsiConstructor = new (domain: string, options: Record<string, unknown>) => JitsiApi;

export function createJitsiVideoClient(container: HTMLElement, handlers: VideoClientHandlers = {}): VideoClient {
  let api: JitsiApi | null = null;
  let stream: MediaStream | null = null;

  return {
    kind: 'jitsi',
    async connect(credentials, local) {
      stream = local;
      handlers.onState?.('connecting');
      const domain = (credentials.server_url ?? '').replace(/^https?:\/\//, '');
      const Ctor = (await loadJitsi(domain)) as JitsiConstructor | null;
      if (!Ctor) throw new Error('jitsi_sdk_unavailable');
      api = new Ctor(domain, {
        roomName: credentials.room,
        jwt: credentials.token,
        parentNode: container,
        configOverwrite: { prejoinPageEnabled: false, disableDeepLinking: true },
        interfaceConfigOverwrite: { TOOLBAR_BUTTONS: [] },
      });
      api.addListener('videoConferenceJoined', () => handlers.onState?.('connected'));
      api.addListener('participantJoined', () => handlers.onRemote?.(true, null));
      api.addListener('participantLeft', () => handlers.onRemote?.(false, null));
      api.addListener('videoConferenceLeft', () => handlers.onState?.('disconnected'));
    },
    setMicrophone(enabled) {
      api?.executeCommand('toggleAudio');
      stream?.getAudioTracks().forEach((t) => { t.enabled = enabled; });
    },
    setCamera(enabled) {
      api?.executeCommand('toggleVideo');
      stream?.getVideoTracks().forEach((t) => { t.enabled = enabled; });
    },
    localStream() {
      return stream;
    },
    remoteStream() {
      return null;                       // Jitsi renders its own iframe; there is no MediaStream to hand back
    },
    async disconnect() {
      api?.dispose();
      api = null;
      handlers.onState?.('disconnected');
    },
  };
}

/**
 * LiveKit: the ONE npm dependency this module has, and it is imported here and nowhere else, always dynamically.
 * `livekit-client` is ~90 KB gzip — more than the entire 95 KB first-load budget of the site route that uses it
 * (REALTIME.md §8) — so a static import anywhere in `resources/js/site/**` would blow that budget for every page
 * on the site at once. Behind `import()` it is a chunk of its own that only a patient who actually presses
 * "Join" on a LiveKit-configured clinic ever downloads; `scripts/check-site-deps.sh` enforces that it is reached
 * this way and no other.
 */
async function loadLiveKit(): Promise<typeof import('livekit-client')> {
  return import('livekit-client');
}

type LiveKitModule = Awaited<ReturnType<typeof loadLiveKit>>;
type LiveKitRoom = InstanceType<LiveKitModule['Room']>;

/**
 * LiveKit's own vocabulary for how a connection is doing is four words; ours is the packet-loss/RTT/bitrate shape
 * `callMachine.qualityFromStats()` grades. This is the translation, chosen so each LiveKit word lands in the
 * band the machine already means by it (`good` → fair, `poor` → poor), and it is the only place the two meet.
 */
const LIVEKIT_QUALITY: Record<string, number> = { excellent: 0, good: 4, poor: 12, lost: 30 };

/**
 * The LiveKit transport, behind the same five-method `VideoClient` the local and Jitsi clients implement — the
 * call machine, the controls and the pre-flight are untouched by it, which is the point of the seam.
 *
 * It publishes the tracks the PRE-FLIGHT already opened rather than asking for the camera a second time: the
 * patient granted permission once, on a screen that explained why, and a second prompt mid-join is how a
 * consultation gets abandoned. Remote media is handed up as an ordinary `MediaStream`, so `VideoStage` attaches
 * it to a `<video>` exactly as it attaches the local preview.
 */
export function createLiveKitVideoClient(handlers: VideoClientHandlers = {}): VideoClient {
  let room: LiveKitRoom | null = null;
  let stream: MediaStream | null = null;
  let remote: MediaStream | null = null;

  const dropRemote = (): void => {
    remote = null;
    handlers.onRemote?.(false, null);
  };

  return {
    kind: 'livekit',
    async connect(credentials, local) {
      stream = local;
      handlers.onState?.('connecting');

      const { Room, RoomEvent } = await loadLiveKit();
      const next = new Room({ adaptiveStream: true, dynacast: true });
      room = next;

      next
        .on(RoomEvent.Connected, () => handlers.onState?.('connected'))
        // A LiveKit reconnect is not a finished call: the machine keeps the mic/camera choices and counts attempts.
        .on(RoomEvent.Reconnecting, () => handlers.onState?.('reconnecting'))
        .on(RoomEvent.Reconnected, () => handlers.onState?.('connected'))
        .on(RoomEvent.Disconnected, () => handlers.onState?.('disconnected'))
        .on(RoomEvent.TrackSubscribed, (track) => {
          remote ??= new MediaStream();
          remote.addTrack(track.mediaStreamTrack);
          handlers.onRemote?.(true, remote);
        })
        .on(RoomEvent.TrackUnsubscribed, (track) => {
          remote?.removeTrack(track.mediaStreamTrack);
          if ((remote?.getTracks().length ?? 0) === 0) dropRemote();
        })
        .on(RoomEvent.ParticipantDisconnected, () => dropRemote())
        .on(RoomEvent.ConnectionQualityChanged, (quality, participant) => {
          if (participant.isLocal) handlers.onQuality?.({ packetLossPct: LIVEKIT_QUALITY[String(quality)] ?? 0 });
        });

      await next.connect(credentials.server_url ?? '', credentials.token);

      for (const track of local?.getTracks() ?? []) {
        await next.localParticipant.publishTrack(track);
      }
    },
    setMicrophone(enabled) {
      stream?.getAudioTracks().forEach((t) => { t.enabled = enabled; });
      room?.localParticipant.audioTrackPublications.forEach((publication) => {
        void (enabled ? publication.track?.unmute() : publication.track?.mute());
      });
    },
    setCamera(enabled) {
      stream?.getVideoTracks().forEach((t) => { t.enabled = enabled; });
      room?.localParticipant.videoTrackPublications.forEach((publication) => {
        void (enabled ? publication.track?.unmute() : publication.track?.mute());
      });
    },
    localStream() {
      return stream;
    },
    remoteStream() {
      return remote;
    },
    async disconnect() {
      await room?.disconnect();
      room = null;
      remote = null;
      handlers.onState?.('disconnected');
    },
  };
}

export interface LoadVideoClientOptions {
  credentials: VideoCredentials;
  container?: HTMLElement | null;
  handlers?: VideoClientHandlers;
}

/**
 * Picks the driver from what the SERVER minted, never from a client-side flag: no `server_url` means the null
 * driver, `jitsi` means the clinic self-hosts, and anything else falls back to local preview rather than
 * pretending to connect. A driver that fails to load degrades to local preview with the error reported.
 */
export async function loadVideoClient({ credentials, container, handlers = {} }: LoadVideoClientOptions): Promise<VideoClient> {
  if (!credentials.server_url) return createLocalVideoClient(handlers);

  // LiveKit. The SDK is downloaded HERE rather than inside `connect()` so that a patient on a bad connection who
  // cannot fetch 90 KB degrades to local preview — the fallback below — instead of watching a join fail. The
  // second `loadLiveKit()` inside the client resolves from the module cache and costs nothing.
  if (credentials.provider === 'livekit') {
    try {
      await loadLiveKit();

      return createLiveKitVideoClient(handlers);
    } catch (error) {
      handlers.onError?.(error);
    }
  }

  if (credentials.provider === 'jitsi' && container) {
    try {
      return createJitsiVideoClient(container, handlers);
    } catch (error) {
      handlers.onError?.(error);
    }
  }

  return createLocalVideoClient(handlers);
}
