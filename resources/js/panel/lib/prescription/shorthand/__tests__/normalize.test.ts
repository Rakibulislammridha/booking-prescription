// PRESCRIPTION.md §2.1 / §2.10 — Bangla digits, unicode fractions, instruction split, spacing.
import { describe, expect, it } from 'vitest';
import { normalize } from '../normalize';
import { amount, bnDigits, decimal, enDigits, parseAmount } from '../numberFormat';

describe('normalize', () => {
  it('maps Bangla digits into `raw` and lower-cases only the body', () => {
    const n = normalize('১+০+১ ১০D AF');
    expect(n.raw).toBe('1+0+1 10D AF');
    expect(n.body).toBe('1+0+1 10d af');
    expect(n.instruction).toBeNull();
  });

  it('expands unicode fractions, × and dashes', () => {
    expect(normalize('½+0+½ 5d').body).toBe('1/2+0+1/2 5d');
    expect(normalize('1+0+1 10d ×2').body).toBe('1+0+1 10d x2');
    expect(normalize('¼ od 5d').body).toBe('1/4 od 5d');
  });

  it('collapses whitespace and removes spaces around + and / between digits', () => {
    expect(normalize('1  +  0 +1   10d').body).toBe('1+0+1 10d');
    expect(normalize('1 / 2 od 5d').body).toBe('1/2 od 5d');
  });

  it('separates digits from words unless the unit is attached', () => {
    expect(normalize('1 tds 5days').body).toBe('1 tds 5days');
    expect(normalize('500mg bd 5d').body).toBe('500mg bd 5d');
    expect(normalize('2tsp bd 5d').body).toBe('2 tsp bd 5d');
  });

  it('splits the instruction at the first marker and keeps its case/script', () => {
    expect(normalize('1+0+1 5d // With Water').instruction).toBe('With Water');
    expect(normalize('0+0+1 7d "খাবারের পরে"').instruction).toBe('খাবারের পরে');
    expect(normalize('0+0+1 7d “after food”').instruction).toBe('after food');
    expect(normalize('1+0+1 5d //   ').instruction).toBeNull();
  });
});

describe('number format', () => {
  it('spells amounts the way the canonical shorthand does', () => {
    expect(amount(1)).toBe('1');
    expect(amount(0.5)).toBe('1/2');
    expect(amount(1.5)).toBe('1 1/2');
    expect(amount(0.25)).toBe('1/4');
    expect(amount(0.75)).toBe('3/4');
    expect(amount(2.3)).toBe('2.3');
  });

  it('trims decimals like PHP number_format(4)', () => {
    expect(decimal(20)).toBe('20');
    expect(decimal(7.5)).toBe('7.5');
    expect(decimal(0)).toBe('0');
    expect(decimal(0.001)).toBe('0.001');
  });

  it('round-trips Bangla and ASCII digits', () => {
    expect(bnDigits('10 d')).toBe('১০ d');
    expect(enDigits('১০ d')).toBe('10 d');
  });

  it('parses fractions', () => {
    expect(parseAmount('1 1/2')).toBe(1.5);
    expect(parseAmount('1/2')).toBe(0.5);
    expect(parseAmount('0.5')).toBe(0.5);
    expect(parseAmount('3')).toBe(3);
  });
});
