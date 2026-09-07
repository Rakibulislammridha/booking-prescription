// REALTIME.md §9.2 / §13.2 — the waiting-room call-out: Web Speech first, clip sequence, chime fallback, dedupe.
import { describe, expect, it, vi } from 'vitest';
import { Announcer, clipSequence, clipUrls, languagesFor, pickVoice, type Announcement, type VoiceAdapter } from '../voice';

function adapter(voices: Array<{ lang: string; name: string }>, clipsThrow = false) {
  const spoken: Array<[string, string, string | null]> = [];
  const clips: string[][] = [];
  const beeps = { count: 0 };
  const impl: VoiceAdapter = {
    voices: () => voices,
    speak: (text, lang, voice) => { spoken.push([text, lang, voice]); return Promise.resolve(); },
    playClips: (urls) => { clips.push(urls); return clipsThrow ? Promise.reject(new Error('no clips')) : Promise.resolve(); },
    beep: () => { beeps.count += 1; return Promise.resolve(); },
    wait: () => Promise.resolve(),
  };
  return { impl, spoken, clips, beeps };
}

const call = (over: Partial<Announcement> = {}): Announcement => ({
  key: 'ser1:2026-09-07T10:44:12Z',
  doctor: 'doc1',
  code: 'A-042',
  room: 'Room 3',
  speak: { bn: 'সিরিয়াল এ বিয়াল্লিশ, রুম তিন', en: 'Serial A forty-two, room three' },
  ...over,
});

describe('voice', () => {
  it('picks a bn voice when the box has one, and an en voice for the English line', () => {
    const voices = [{ lang: 'en-US', name: 'Daniel' }, { lang: 'bn-BD', name: 'Bangla' }];
    expect(pickVoice(voices, 'bn')).toBe('Bangla');
    expect(pickVoice(voices, 'en')).toBe('Daniel');
    expect(pickVoice([{ lang: 'hi-IN', name: 'Hindi' }], 'bn')).toBeNull();
  });

  it('speaks the server-rendered lines in both languages when voices exist', async () => {
    const a = adapter([{ lang: 'bn-BD', name: 'Bangla' }, { lang: 'en-GB', name: 'Daniel' }]);
    const announcer = new Announcer(a.impl, 'both');
    announcer.enqueue(call());
    await vi.waitFor(() => expect(a.spoken).toHaveLength(2));
    expect(a.spoken[0]).toEqual(['সিরিয়াল এ বিয়াল্লিশ, রুম তিন', 'bn', 'Bangla']);
    expect(a.spoken[1]).toEqual(['Serial A forty-two, room three', 'en', 'Daniel']);
    expect(a.clips).toHaveLength(0);
  });

  it('falls back to the clip sequence chime, serial, A, 4, 2, room, 3 for A-042 in Room 3', () => {
    expect(clipSequence('A-042', 'Room 3')).toEqual(['chime', 'serial', 'A', '4', '2', 'room', '3']);
    expect(clipSequence('B-007', null)).toEqual(['chime', 'serial', 'B', '7']);
    expect(clipUrls('bn', 'A-042', 'Room 3')[0]).toBe('/audio/bn/chime.mp3');
    expect(clipUrls('en', 'A-042', 'Room 3')).toHaveLength(7);
  });

  it('uses clips when no voice matches, and a chime when the clips are missing too', async () => {
    const withClips = adapter([]);
    const a1 = new Announcer(withClips.impl, 'bn');
    a1.enqueue(call());
    await vi.waitFor(() => expect(withClips.clips).toHaveLength(1));
    expect(withClips.clips[0]).toEqual(clipUrls('bn', 'A-042', 'Room 3'));
    expect(withClips.beeps.count).toBe(0);

    const noClips = adapter([], true);
    const a2 = new Announcer(noClips.impl, 'bn');
    a2.enqueue(call());
    await vi.waitFor(() => expect(noClips.beeps.count).toBe(1));
  });

  it('speaks each call once, deduped by serial and called_at', async () => {
    const a = adapter([{ lang: 'bn-BD', name: 'Bangla' }]);
    const announcer = new Announcer(a.impl, 'bn');
    announcer.enqueue(call());
    announcer.enqueue(call());
    await vi.waitFor(() => expect(a.spoken).toHaveLength(1));

    announcer.enqueue(call({ key: 'ser1:2026-09-07T10:52:00Z' }));   // same serial, called again
    await vi.waitFor(() => expect(a.spoken).toHaveLength(2));
  });

  it('keeps only the latest announcement per doctor and at most three pending', () => {
    const a = adapter([{ lang: 'bn-BD', name: 'Bangla' }]);
    const announcer = new Announcer({ ...a.impl, speak: () => new Promise(() => undefined) }, 'bn');
    announcer.enqueue(call({ key: 'k1', doctor: 'doc1' }));      // starts speaking (never resolves)
    announcer.enqueue(call({ key: 'k2', doctor: 'doc2' }));
    announcer.enqueue(call({ key: 'k3', doctor: 'doc3' }));
    announcer.enqueue(call({ key: 'k4', doctor: 'doc4' }));
    announcer.enqueue(call({ key: 'k5', doctor: 'doc2' }));       // replaces doc2's pending one
    expect(announcer.queued).toBeLessThanOrEqual(3);
  });

  it('off speaks nothing and a single language speaks one line', () => {
    expect(languagesFor('off')).toEqual([]);
    expect(languagesFor('both')).toEqual(['bn', 'en']);
    expect(languagesFor('bn')).toEqual(['bn']);
    expect(languagesFor('en')).toEqual(['en']);

    const a = adapter([{ lang: 'bn-BD', name: 'Bangla' }]);
    const announcer = new Announcer(a.impl, 'off');
    announcer.enqueue(call());
    expect(a.spoken).toHaveLength(0);
  });
});
