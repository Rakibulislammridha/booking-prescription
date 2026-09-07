// Waiting-room voice call-out (REALTIME.md §9.2). The server sends ready-made Bangla/English lines in
// `call.next.speak`, so this file never needs a number-to-words library: it picks a voice, speaks, and falls back to
// pre-recorded clips (/audio/{bn,en}/…) and finally to a two-tone chime when the box has neither.
// Every browser API is injected so the unit tests can drive it without a speech engine.

export type VoiceMode = 'both' | 'bn' | 'en' | 'off';

export interface Announcement {
  key: string;              // dedupe key: serial id + called_at
  doctor: string;           // one pending announcement per doctor survives a burst
  code: string;             // A-042
  room: string | null;
  speak: { bn: string; en: string };
}

export interface VoiceAdapter {
  voices(): Array<{ lang: string; name: string }>;
  speak(text: string, lang: 'bn' | 'en', voiceName: string | null): Promise<void>;
  playClips(urls: string[]): Promise<void>;
  beep(): Promise<void>;
  wait(ms: number): Promise<void>;
}

export const MAX_PENDING = 3;
export const GAP_MS = 1_000;
export const CLIP_BASE = '/audio';

/** A voice whose `lang` starts with the requested prefix, or null. */
export function pickVoice(voices: Array<{ lang: string; name: string }>, prefix: 'bn' | 'en'): string | null {
  const hit = voices.find((v) => (v.lang ?? '').toLowerCase().startsWith(prefix));
  return hit ? hit.name : null;
}

/**
 * Clip fallback: digits are read one by one, which is how numbers are called in BD halls and avoids recording every
 * number. `A-042`, room 3 → chime, serial, A, 4, 2, room, 3.
 */
export function clipSequence(code: string, room: string | null): string[] {
  const [letter = '', digits = ''] = code.split('-');
  const parts = ['chime', 'serial', letter.toUpperCase(), ...String(Number(digits) || 0).split('')];
  const roomDigits = room ? (room.match(/\d/g) ?? []) : [];
  if (roomDigits.length > 0) parts.push('room', ...roomDigits);
  return parts;
}

export function clipUrls(lang: 'bn' | 'en', code: string, room: string | null): string[] {
  return clipSequence(code, room).map((part) => `${CLIP_BASE}/${lang}/${part}.mp3`);
}

/** Languages spoken for a tenant setting, in the order they are announced. */
export function languagesFor(mode: VoiceMode): Array<'bn' | 'en'> {
  return mode === 'off' ? [] : mode === 'both' ? ['bn', 'en'] : [mode];
}

export class Announcer {
  private readonly spoken = new Set<string>();

  private pending: Announcement[] = [];

  private running = false;

  constructor(
    private readonly adapter: VoiceAdapter,
    private mode: VoiceMode = 'both',
  ) {}

  setMode(mode: VoiceMode): void {
    this.mode = mode;
  }

  /** Each call is spoken once (dedupe by serial + called_at); a burst keeps only the latest per doctor. */
  enqueue(item: Announcement): void {
    if (this.mode === 'off' || this.spoken.has(item.key)) return;
    this.spoken.add(item.key);
    this.pending = this.pending.filter((p) => p.doctor !== item.doctor);
    this.pending.push(item);
    if (this.pending.length > MAX_PENDING) this.pending = this.pending.slice(-MAX_PENDING);
    void this.drain();
  }

  get queued(): number {
    return this.pending.length;
  }

  private async drain(): Promise<void> {
    if (this.running) return;
    this.running = true;
    try {
      while (this.pending.length > 0) {
        const next = this.pending.shift();
        if (!next) break;
        await this.say(next);
        if (this.pending.length > 0) await this.adapter.wait(GAP_MS);
      }
    } finally {
      this.running = false;
    }
  }

  private async say(item: Announcement): Promise<void> {
    const voices = this.adapter.voices();
    for (const lang of languagesFor(this.mode)) {
      const voice = pickVoice(voices, lang);
      if (voice !== null) {
        await this.adapter.speak(item.speak[lang], lang, voice);
        continue;
      }
      try {
        await this.adapter.playClips(clipUrls(lang, item.code, item.room));
      } catch {
        await this.adapter.beep();
      }
    }
  }
}

/** The browser adapter: Web Speech API, <audio> clips, and a WebAudio two-tone chime as the last resort. */
export function browserAdapter(): VoiceAdapter {
  let context: AudioContext | null = null;

  const audioContext = (): AudioContext | null => {
    if (context) return context;
    const Ctor = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
    context = Ctor ? new Ctor() : null;
    return context;
  };

  return {
    voices: () => (typeof speechSynthesis === 'undefined' ? [] : speechSynthesis.getVoices().map((v) => ({ lang: v.lang, name: v.name }))),
    speak: (text, lang, voiceName) =>
      new Promise((resolve) => {
        if (typeof speechSynthesis === 'undefined') { resolve(); return; }
        const utterance = new SpeechSynthesisUtterance(text);
        utterance.lang = lang === 'bn' ? 'bn-BD' : 'en-US';
        utterance.rate = 0.9;
        const voice = speechSynthesis.getVoices().find((v) => v.name === voiceName);
        if (voice) utterance.voice = voice;
        utterance.onend = () => resolve();
        utterance.onerror = () => resolve();
        speechSynthesis.speak(utterance);
      }),
    playClips: async (urls) => {
      for (const url of urls) {
        await new Promise<void>((resolve, reject) => {
          const audio = new Audio(url);
          audio.onended = () => resolve();
          audio.onerror = () => reject(new Error(`clip ${url}`));
          void audio.play().catch(() => reject(new Error(`clip ${url}`)));
        });
      }
    },
    beep: async () => {
      const ctx = audioContext();
      if (!ctx) return;
      for (const [frequency, ms] of [[880, 180], [660, 220]] as const) {
        const oscillator = ctx.createOscillator();
        const gain = ctx.createGain();
        oscillator.frequency.value = frequency;
        gain.gain.value = 0.15;
        oscillator.connect(gain).connect(ctx.destination);
        oscillator.start();
        await new Promise((r) => setTimeout(r, ms));
        oscillator.stop();
      }
    },
    wait: (ms) => new Promise((r) => setTimeout(r, ms)),
  };
}

/** The autoplay unlock of §9.2.3: the first tap creates the context and plays a silent buffer. */
export async function unlockAudio(): Promise<void> {
  const Ctor = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
  if (!Ctor) return;
  const ctx = new Ctor();
  await ctx.resume().catch(() => undefined);
  const source = ctx.createBufferSource();
  source.buffer = ctx.createBuffer(1, 1, 22_050);
  source.connect(ctx.destination);
  source.start(0);
}
