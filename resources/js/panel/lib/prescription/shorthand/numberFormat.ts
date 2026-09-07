// Amount spelling shared by `normalized` / `dose_schedule` — mirrors App\Domain\Prescription\Shorthand\NumberFormat
// byte for byte (fractions as 1/2, 1 1/2; else trimmed decimals).

const BN = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'] as const;
const EPS = 1e-9;

/** PHP `rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.')`: 7.5 → "7.5", 20.0 → "20". */
export function decimal(value: number): string {
  if (!Number.isFinite(value)) return '0';
  let s = value.toFixed(4);
  s = s.replace(/0+$/, '');
  s = s.replace(/\.$/, '');
  return s === '-0' ? '0' : s;
}

export function amount(value: number): string {
  const whole = Math.floor(value + EPS);
  const frac = value - whole;
  let fraction: string | null;
  if (Math.abs(frac) < EPS) fraction = '';
  else if (Math.abs(frac - 0.5) < EPS) fraction = '1/2';
  else if (Math.abs(frac - 0.25) < EPS) fraction = '1/4';
  else if (Math.abs(frac - 0.75) < EPS) fraction = '3/4';
  else fraction = null;

  if (fraction === null) return decimal(value);
  if (fraction === '') return String(whole);
  return whole === 0 ? fraction : `${whole} ${fraction}`;
}

export function bnDigits(text: string): string {
  return text.replace(/[0-9]/g, (d) => BN[Number(d)] ?? d);
}

export function enDigits(text: string): string {
  return text.replace(/[০-৯]/g, (d) => String(BN.indexOf(d as (typeof BN)[number])));
}

/** Parses "1", "1/2", "1 1/2", "0.5" (ASCII digits) to a float. */
export function parseAmount(text: string): number {
  const t = text.trim();
  let m = /^(\d+)\s+(\d+)\/(\d+)$/.exec(t);
  if (m) {
    const den = Number(m[3]);
    return Number(m[1]) + (den === 0 ? 0 : Number(m[2]) / den);
  }
  m = /^(\d+)\/(\d+)$/.exec(t);
  if (m) {
    const den = Number(m[2]);
    return den === 0 ? 0 : Number(m[1]) / den;
  }
  const value = Number.parseFloat(t);
  return Number.isNaN(value) ? 0 : value;
}

/** PHP round(): half away from zero, at `precision` decimals. */
export function phpRound(value: number, precision = 0): number {
  const factor = 10 ** precision;
  const scaled = value * factor;
  // Guard the binary representation the way PHP's pre-rounding does (1.005 * 100 = 100.49999…).
  const corrected = Number(scaled.toPrecision(15));
  const rounded = corrected < 0 ? -Math.round(-corrected) : Math.round(corrected);
  return rounded / factor;
}

/** PHP ceil() with the 1e-9 slack the quantity maths uses. */
export function ceilSlack(value: number): number {
  return Math.ceil(value - EPS);
}
