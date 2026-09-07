import { beforeEach, describe, expect, it } from 'vitest';
import { isUlid, resetUlidState, ulid, ulidTime } from '../ulid';

beforeEach(() => resetUlidState());

describe('ulid', () => {
  it('is 26 Crockford base32 characters', () => {
    const id = ulid();
    expect(id).toHaveLength(26);
    expect(isUlid(id)).toBe(true);
    expect(isUlid(id.toLowerCase())).toBe(false);
    expect(isUlid('01ARZ3NDEKTSV4RRFFQ69G5FAI')).toBe(false); // I is not in the alphabet
  });

  it('encodes the timestamp in the first 10 characters', () => {
    const t = 1469918176385; // spec example 01ARYZ6S41TSV4RRFFQ69G5FAV
    const id = ulid(t);
    expect(ulidTime(id)).toBe(t);
    expect(id.startsWith('01ARYZ6S41')).toBe(true);
  });

  it('is monotonic within the same millisecond and unique across a burst', () => {
    const t = Date.now();
    const ids = Array.from({ length: 500 }, () => ulid(t));
    const sorted = [...ids].sort();
    expect(ids).toEqual(sorted);
    expect(new Set(ids).size).toBe(ids.length);
    expect(ids.every((id) => id.slice(0, 10) === ids[0]?.slice(0, 10))).toBe(true);
  });

  it('sorts later milliseconds after earlier ones', () => {
    const a = ulid(1000);
    const b = ulid(1001);
    expect(a < b).toBe(true);
  });

  it('rejects out-of-range time', () => {
    expect(() => ulid(-1)).toThrow(RangeError);
    expect(() => ulid(2 ** 48)).toThrow(RangeError);
  });
});
