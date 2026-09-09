// The browser half of the provider-agnostic video layer, with `livekit-client` MOCKED — the suite never opens a
// socket, never downloads an SDK and never touches a camera, exactly as the PHP suite never reaches a real video
// service. What is asserted is the SEAM: that the LiveKit transport implements the same five-method `VideoClient`
// the local and Jitsi clients do, that it publishes the tracks the pre-flight already opened, and that LiveKit's
// own connection vocabulary arrives at the call machine as the four states the machine knows.
import { beforeEach, describe, expect, it, vi } from 'vitest';

type Listener = (...args: unknown[]) => void;

const livekit = vi.hoisted(() => {
  const RoomEvent = {
    Connected: 'connected',
    Reconnecting: 'reconnecting',
    Reconnected: 'reconnected',
    Disconnected: 'disconnected',
    TrackSubscribed: 'trackSubscribed',
    TrackUnsubscribed: 'trackUnsubscribed',
    ParticipantDisconnected: 'participantDisconnected',
    ConnectionQualityChanged: 'connectionQualityChanged',
  };

  class FakePublication {
    muted = false;

    track = {
      mute: async (): Promise<void> => { this.muted = true; },
      unmute: async (): Promise<void> => { this.muted = false; },
    };
  }

  class FakeRoom {
    static last: FakeRoom | null = null;

    static failConnect = false;

    listeners = new Map<string, Listener[]>();

    connectedTo: { url: string; token: string } | null = null;

    published: MediaStreamTrack[] = [];

    disconnected = false;

    options: unknown;

    audio = new FakePublication();

    video = new FakePublication();

    localParticipant = {
      publishTrack: async (track: MediaStreamTrack): Promise<void> => { this.published.push(track); },
      audioTrackPublications: new Map([['a', this.audio]]),
      videoTrackPublications: new Map([['v', this.video]]),
    };

    constructor(options?: unknown) {
      this.options = options;
      FakeRoom.last = this;
    }

    on(event: string, handler: Listener): this {
      this.listeners.set(event, [...(this.listeners.get(event) ?? []), handler]);

      return this;
    }

    emit(event: string, ...args: unknown[]): void {
      (this.listeners.get(event) ?? []).forEach((handler) => handler(...args));
    }

    async connect(url: string, token: string): Promise<void> {
      if (FakeRoom.failConnect) throw new Error('livekit_connect_failed');
      this.connectedTo = { url, token };
    }

    async disconnect(): Promise<void> {
      this.disconnected = true;
    }
  }

  return { RoomEvent, FakeRoom };
});

vi.mock('livekit-client', () => ({ Room: livekit.FakeRoom, RoomEvent: livekit.RoomEvent }));

// jsdom has no MediaStream; the transport builds one to hand remote tracks up to <VideoStage>.
class FakeMediaStream {
  tracks: MediaStreamTrack[] = [];

  addTrack(track: MediaStreamTrack): void { this.tracks.push(track); }

  removeTrack(track: MediaStreamTrack): void { this.tracks = this.tracks.filter((t) => t !== track); }

  getTracks(): MediaStreamTrack[] { return this.tracks; }

  getAudioTracks(): MediaStreamTrack[] { return this.tracks.filter((t) => t.kind === 'audio'); }

  getVideoTracks(): MediaStreamTrack[] { return this.tracks.filter((t) => t.kind === 'video'); }
}

globalThis.MediaStream = FakeMediaStream as unknown as typeof MediaStream;

const { createLiveKitVideoClient, loadVideoClient } = await import('../core/videoClient');
type Credentials = Parameters<typeof loadVideoClient>[0]['credentials'];

function track(kind: 'audio' | 'video'): MediaStreamTrack {
  return { kind, enabled: true, stop: () => undefined } as unknown as MediaStreamTrack;
}

function localStream(): MediaStream {
  const stream = new FakeMediaStream();
  stream.addTrack(track('audio'));
  stream.addTrack(track('video'));

  return stream as unknown as MediaStream;
}

function credentials(overrides: Partial<Credentials> = {}): Credentials {
  return {
    provider: 'livekit',
    room: 't9001-01jabcdefghjkmnpqrstvwxyz0',
    token: 'a.minted.token',
    identity: 'patient-abc123',
    server_url: 'wss://livekit.example.test',
    ...overrides,
  };
}

beforeEach(() => {
  livekit.FakeRoom.last = null;
  livekit.FakeRoom.failConnect = false;
});

describe('the LiveKit transport', () => {
  it('connects with the server URL and token the server minted, and nothing else', async () => {
    const states: string[] = [];
    const client = createLiveKitVideoClient({ onState: (s) => states.push(s) });

    await client.connect(credentials(), localStream());

    expect(client.kind).toBe('livekit');
    expect(livekit.FakeRoom.last?.connectedTo).toEqual({ url: 'wss://livekit.example.test', token: 'a.minted.token' });
    expect(states).toEqual(['connecting']);
  });

  it('publishes the tracks the pre-flight already opened rather than asking for the camera again', async () => {
    const stream = localStream();
    await createLiveKitVideoClient().connect(credentials(), stream);

    expect(livekit.FakeRoom.last?.published).toEqual(stream.getTracks());
  });

  it('publishes nothing when the pre-flight handed it no stream', async () => {
    await createLiveKitVideoClient().connect(credentials(), null);

    expect(livekit.FakeRoom.last?.published).toEqual([]);
  });

  it("translates LiveKit's connection events into the call machine's four states", async () => {
    const states: string[] = [];
    await createLiveKitVideoClient({ onState: (s) => states.push(s) }).connect(credentials(), null);
    const room = livekit.FakeRoom.last;

    room?.emit('connected');
    room?.emit('reconnecting');
    room?.emit('reconnected');
    room?.emit('disconnected');

    expect(states).toEqual(['connecting', 'connected', 'reconnecting', 'connected', 'disconnected']);
  });

  it('hands a subscribed remote track up as a MediaStream and clears it when the participant leaves', async () => {
    const remotes: Array<[boolean, MediaStream | null]> = [];
    const client = createLiveKitVideoClient({ onRemote: (present, stream) => remotes.push([present, stream]) });
    await client.connect(credentials(), null);
    const room = livekit.FakeRoom.last;
    const remoteVideo = track('video');

    room?.emit('trackSubscribed', { mediaStreamTrack: remoteVideo });

    const [present, arrived] = remotes[0] ?? [false, null];
    expect(present).toBe(true);
    expect(arrived?.getTracks()).toEqual([remoteVideo]);
    expect(client.remoteStream()).toBe(arrived);

    room?.emit('participantDisconnected');

    expect(remotes[1]).toEqual([false, null]);
    expect(client.remoteStream()).toBeNull();
  });

  it('drops the remote stream when its last track is unsubscribed', async () => {
    const remotes: Array<[boolean, MediaStream | null]> = [];
    const client = createLiveKitVideoClient({ onRemote: (present, stream) => remotes.push([present, stream]) });
    await client.connect(credentials(), null);
    const room = livekit.FakeRoom.last;
    const audio = track('audio');
    const video = track('video');

    room?.emit('trackSubscribed', { mediaStreamTrack: audio });
    room?.emit('trackSubscribed', { mediaStreamTrack: video });
    room?.emit('trackUnsubscribed', { mediaStreamTrack: video });

    expect(client.remoteStream()?.getTracks()).toEqual([audio]);

    room?.emit('trackUnsubscribed', { mediaStreamTrack: audio });

    expect(client.remoteStream()).toBeNull();
    expect(remotes.at(-1)).toEqual([false, null]);
  });

  it('grades only the LOCAL connection quality, in the packet-loss shape the machine reads', async () => {
    const stats: Array<number | undefined> = [];
    await createLiveKitVideoClient({ onQuality: (s) => stats.push(s.packetLossPct) }).connect(credentials(), null);
    const room = livekit.FakeRoom.last;

    room?.emit('connectionQualityChanged', 'excellent', { isLocal: true });
    room?.emit('connectionQualityChanged', 'good', { isLocal: true });
    room?.emit('connectionQualityChanged', 'poor', { isLocal: true });
    room?.emit('connectionQualityChanged', 'lost', { isLocal: true });
    room?.emit('connectionQualityChanged', 'poor', { isLocal: false });

    expect(stats).toEqual([0, 4, 12, 30]);
  });

  it('mutes the published track AND the local preview on the mic and camera toggles', async () => {
    const stream = localStream();
    const client = createLiveKitVideoClient();
    await client.connect(credentials(), stream);
    const room = livekit.FakeRoom.last;

    client.setMicrophone(false);
    client.setCamera(false);
    await Promise.resolve();

    expect(room?.audio.muted).toBe(true);
    expect(room?.video.muted).toBe(true);
    expect(stream.getAudioTracks()[0]?.enabled).toBe(false);
    expect(stream.getVideoTracks()[0]?.enabled).toBe(false);

    client.setMicrophone(true);
    client.setCamera(true);
    await Promise.resolve();

    expect(room?.audio.muted).toBe(false);
    expect(room?.video.muted).toBe(false);
    expect(stream.getAudioTracks()[0]?.enabled).toBe(true);
  });

  it('lets a failed connect reject, so the call machine fails the join rather than hanging', async () => {
    livekit.FakeRoom.failConnect = true;

    await expect(createLiveKitVideoClient().connect(credentials(), null)).rejects.toThrow('livekit_connect_failed');
  });

  it('disconnects the room, drops the remote stream and reports the state', async () => {
    const states: string[] = [];
    const client = createLiveKitVideoClient({ onState: (s) => states.push(s) });
    await client.connect(credentials(), localStream());
    const room = livekit.FakeRoom.last;

    await client.disconnect();

    expect(room?.disconnected).toBe(true);
    expect(client.remoteStream()).toBeNull();
    expect(states).toEqual(['connecting', 'disconnected']);

    // A second disconnect (the unmount after a hang-up) must not throw on a room that is already gone.
    await expect(client.disconnect()).resolves.toBeUndefined();
  });
});

describe('loadVideoClient', () => {
  it('picks the LiveKit transport for a LiveKit credential', async () => {
    const client = await loadVideoClient({ credentials: credentials() });

    expect(client.kind).toBe('livekit');
  });

  it('falls back to local preview when the SDK cannot be downloaded', async () => {
    // A patient on a bad connection who cannot fetch 90 KB of SDK gets their own camera and the waiting-room
    // copy, not a broken page — the same degradation a clinic with no video service at all gets.
    vi.resetModules();
    vi.doMock('livekit-client', () => { throw new Error('sdk_unavailable'); });

    try {
      const fresh = await import('../core/videoClient');
      const errors: unknown[] = [];

      const client = await fresh.loadVideoClient({ credentials: credentials(), handlers: { onError: (e) => errors.push(e) } });

      expect(client.kind).toBe('local');
      expect(errors).toHaveLength(1);
    } finally {
      vi.doUnmock('livekit-client');
      vi.resetModules();
    }
  });

  it('runs local preview when the server minted no server URL at all', async () => {
    const client = await loadVideoClient({ credentials: credentials({ provider: 'jitsi', server_url: null }) });

    expect(client.kind).toBe('local');
  });
});
