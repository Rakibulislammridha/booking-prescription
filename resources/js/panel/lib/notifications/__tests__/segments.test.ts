// The browser counter must agree with App\Domain\Notifications\Services\SegmentCounter to the digit — the editor
// shows one number and the clinic is invoiced for another otherwise. These are the same cases as
// tests/Unit/Notifications/SegmentCounterTest.php.
import { describe, expect, it } from 'vitest';
import { containsBengali, countSegments, isGsm7 } from '../segments';

describe('countSegments', () => {
  it('treats plain Latin as GSM-7', () => {
    expect(isGsm7('Serial A-012 with Dr. Rahman at 10:00 am.')).toBe(true);
    expect(containsBengali('Serial A-012')).toBe(false);
  });

  it('treats any Bengali character as UCS-2', () => {
    expect(isGsm7('Serial A-012 — সিরিয়াল')).toBe(false);
    expect(containsBengali('সিরিয়াল')).toBe(true);
  });

  it('breaks GSM-7 at 160 and packs 153 per part after that', () => {
    expect(countSegments('a'.repeat(160))).toMatchObject({ encoding: 'GSM-7', segments: 1, remaining: 0 });
    expect(countSegments('a'.repeat(161)).segments).toBe(2);
    expect(countSegments('a'.repeat(306)).segments).toBe(2);
    expect(countSegments('a'.repeat(307)).segments).toBe(3);
  });

  it('breaks Bangla at 70, not 160', () => {
    expect(countSegments('ক'.repeat(70))).toMatchObject({ encoding: 'UCS-2', units: 70, segments: 1, per_segment: 70 });
    expect(countSegments('ক'.repeat(71)).segments).toBe(2);
  });

  it('packs 67 UCS-2 units per concatenated part', () => {
    expect(countSegments('ক'.repeat(134)).segments).toBe(2);
    expect(countSegments('ক'.repeat(135)).segments).toBe(3);
    expect(countSegments('ক'.repeat(201)).segments).toBe(3);
    expect(countSegments('ক'.repeat(202)).segments).toBe(4);
  });

  it('lets one Bengali character make a mostly-Latin message Unicode', () => {
    expect(countSegments(`${'a'.repeat(80)}ক`)).toMatchObject({ encoding: 'UCS-2', units: 81, segments: 2 });
  });

  it('charges two septets for a GSM extension character and never splits the pair', () => {
    expect(countSegments(`${'a'.repeat(158)}{}`)).toMatchObject({ encoding: 'GSM-7', units: 162, segments: 2 });
    expect(countSegments(`${'a'.repeat(152)}{${'a'.repeat(200)}`)).toMatchObject({ units: 354, segments: 3 });
  });

  it('counts an emoji as two UTF-16 code units and never splits the surrogate pair', () => {
    expect(countSegments('🙂')).toMatchObject({ encoding: 'UCS-2', units: 2, segments: 1 });
    expect(countSegments(`${'ক'.repeat(66)}🙂${'ক'.repeat(40)}`)).toMatchObject({ units: 108, segments: 2 });
  });

  it('matches the PHP counter on the real Bangla "3 ahead" alert', () => {
    const body =
      'সেবা হাসপাতাল: আপনার আগে আর ৩ জন রোগী আছেন। এখনই চেম্বারের সামনে আসুন। সিরিয়াল A-012, ডা. রহমান। ক্রম পরিবর্তন হতে পারে।';
    expect(countSegments(body)).toMatchObject({ encoding: 'UCS-2', units: 121, segments: 2 });
  });

  it('reports an empty body as one empty GSM-7 part', () => {
    expect(countSegments('')).toMatchObject({ encoding: 'GSM-7', units: 0, segments: 1, remaining: 160 });
  });
});
