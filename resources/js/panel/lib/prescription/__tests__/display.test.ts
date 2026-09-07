// The interpretation line and the quantity the doctor reads while typing (§2.13). These strings are the same ones
// DisplayFormatter freezes into the snapshot, so a mismatch here is a mismatch on the printed pad.
import { describe, expect, it } from 'vitest';
import type { ParseContext } from '@shared/types/models';
import { complaintDuration, itemDisplay, quantityText, splitComplaint } from '../display';
import { parseLine } from '../shorthand/parse';

const tab: ParseContext = { form_code: 'tab', default_unit: 'tab', pack_size: null, pack_unit: null, strength_mg: 500, per_ml: null, is_liquid: false, cont_days: 30, locale: 'en', route_code: 'po', strength_label: '500 mg', form_label: 'tablet' };
const syr: ParseContext = { form_code: 'syr', default_unit: 'tsp', pack_size: 100, pack_unit: 'bottle', strength_mg: 120, per_ml: 24, is_liquid: true, cont_days: 30, locale: 'en', route_code: 'po', strength_label: '120 mg/5 ml', form_label: 'syrup' };

describe('interpretation line', () => {
  it('reads back a slot schedule in both languages', () => {
    const line = parseLine('1+0+1 10d af', tab);
    expect(itemDisplay('en', line).interpretation).toBe('1 + 0 + 1 tab · 10 days · after meal · 20 tab');
    const bn = itemDisplay('bn', line);
    expect(bn.duration).toBe('১০ দিন');
    expect(bn.timing).toBe('খাবারের পরে');
    expect(bn.quantity).toBe('২০টি');
  });

  it('reads back a frequency, an interval, stat and sos', () => {
    expect(itemDisplay('en', parseLine('1 tds 5d', tab)).dose).toBe('1 tab three times daily');
    expect(itemDisplay('en', parseLine('1 q6h 3d', tab)).dose).toBe('1 tab every 6 hours');
    expect(itemDisplay('en', parseLine('2 stat', tab)).dose).toBe('2 tab stat (once now)');
    expect(itemDisplay('en', parseLine('1 sos max 3 5d', tab)).dose).toBe('1 tab when needed, max 3/day');
  });

  it('shows the dispensed quantity in the pack unit of the family', () => {
    expect(quantityText('en', parseLine('1 tsp tds 5d', syr))).toBe('1 bottle');
    expect(quantityText('en', parseLine('1/2+0+1/2 5d', tab))).toBe('5 tab');
    expect(quantityText('bn', parseLine('1/2+0+1/2 5d', tab))).toBe('৫টি');
  });

  it('shows nothing rather than a guess when the quantity is unknown', () => {
    expect(quantityText('en', parseLine('1+0+1', tab))).toBe('');
    expect(itemDisplay('en', parseLine('1+0+1', tab)).interpretation).toBe('1 + 0 + 1 tab');
  });
});

describe('complaint durations', () => {
  it('splits a trailing duration token off the text', () => {
    expect(splitComplaint('fever 3d')).toEqual({ text: 'fever', duration: '3d' });
    expect(splitComplaint('জ্বর ৩ দিন')).toEqual({ text: 'জ্বর ৩ দিন', duration: null });
    expect(splitComplaint('cough 2 weeks')).toEqual({ text: 'cough', duration: '2weeks' });
    expect(splitComplaint('headache')).toEqual({ text: 'headache', duration: null });
  });

  it('labels a duration in both languages', () => {
    expect(complaintDuration('3d')).toEqual({ bn: '৩ দিন', en: '3 days' });
    expect(complaintDuration('1d')).toEqual({ bn: '১ দিন', en: '1 day' });
    expect(complaintDuration('2w')).toEqual({ bn: '২ সপ্তাহ', en: '2 weeks' });
    expect(complaintDuration(null)).toEqual({ bn: '', en: '' });
  });
});
