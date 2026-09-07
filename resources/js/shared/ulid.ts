// The one client-side id generator (client_event_id, prescription writer item keys — ARCHITECTURE §7.1).
// 26 chars, Crockford base32, 48-bit ms timestamp + 80 bits of randomness, monotonic within one millisecond
// so ids generated in a burst sort in creation order (needed by the offline event log, OFFLINE.md §6).

const ENCODING = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
const TIME_LEN = 10;
const RANDOM_LEN = 16;
const ULID_RE = /^[0-9A-HJKMNP-TV-Z]{26}$/;

let lastTime = -1;
let lastRandom: number[] = [];

function randomDigits(): number[] {
  const bytes = new Uint8Array(RANDOM_LEN);
  const c = globalThis.crypto;
  if (c && typeof c.getRandomValues === 'function') {
    c.getRandomValues(bytes);
  } else {
    for (let i = 0; i < RANDOM_LEN; i++) bytes[i] = Math.floor(Math.random() * 256);
  }
  const out: number[] = new Array<number>(RANDOM_LEN);
  for (let i = 0; i < RANDOM_LEN; i++) out[i] = (bytes[i] ?? 0) & 31;
  return out;
}

function encodeTime(now: number): string {
  let value = now;
  let out = '';
  for (let i = 0; i < TIME_LEN; i++) {
    out = ENCODING.charAt(value % 32) + out;
    value = Math.floor(value / 32);
  }
  return out;
}

/** Increment the base32 digit vector by one (monotonic step). Throws on overflow (2^80 ids in one ms). */
function increment(digits: number[]): number[] {
  const next = digits.slice();
  for (let i = next.length - 1; i >= 0; i--) {
    const d = (next[i] ?? 0) + 1;
    if (d < 32) {
      next[i] = d;
      return next;
    }
    next[i] = 0;
  }
  throw new Error('ulid: random component overflow');
}

export function ulid(now: number = Date.now()): string {
  if (!Number.isFinite(now) || now < 0 || now > 281474976710655) throw new RangeError('ulid: time out of range');
  const t = Math.floor(now);
  if (t === lastTime) {
    lastRandom = increment(lastRandom);
  } else {
    lastTime = t;
    lastRandom = randomDigits();
  }
  let random = '';
  for (const d of lastRandom) random += ENCODING.charAt(d);
  return encodeTime(t) + random;
}

export function isUlid(value: unknown): value is string {
  return typeof value === 'string' && ULID_RE.test(value);
}

/** Milliseconds since epoch encoded in the id. */
export function ulidTime(id: string): number {
  if (!isUlid(id)) throw new TypeError('ulidTime: not a ULID');
  let value = 0;
  for (let i = 0; i < TIME_LEN; i++) value = value * 32 + ENCODING.indexOf(id.charAt(i));
  return value;
}

/** Test seam: forget the monotonic state. */
export function resetUlidState(): void {
  lastTime = -1;
  lastRandom = [];
}
