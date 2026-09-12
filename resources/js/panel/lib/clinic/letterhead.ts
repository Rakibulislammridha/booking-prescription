// The letterhead's client half — the mirror of `App\Domain\Prescription\Data\{Letterhead,LetterheadColumn,
// LetterheadLine}`, the way `padGeometry.ts` mirrors `PadGeometry`. The same bounds, the same fallbacks, the same
// defaults, so a line the editor lets you build is a line the validator accepts and the print partials draw.
//
// Everything here is pure: the editor mutates immutably through these helpers and the preview renders through
// `lineSx()`, which means what you see moving in the preview is literally the object that will be PUT.
import type { Letterhead, LetterheadAlign, LetterheadColor, LetterheadColumn, LetterheadLine } from '@shared/types/models';

/** Letterhead::MAX_LINES / MAX_COLUMNS and LetterheadLine::SIZE_MIN / SIZE_MAX / MAX_TEXT. */
export const MAX_LINES = 20;
export const MAX_COLUMNS = 3;
export const MAX_TEXT = 160;
export const SIZE_MIN = 0.7;
export const SIZE_MAX = 2.0;

export const LETTERHEAD_COLORS: LetterheadColor[] = ['accent', 'text', 'muted'];
export const LETTERHEAD_ALIGNS: LetterheadAlign[] = ['left', 'center', 'right'];

export const DEFAULT_ACCENT = '#B03A2E';
export const DEFAULT_TEXT = '#1A1A1A';
export const DEFAULT_MUTED = '#666666';

/** A new line starts as body text at body size: the doctor styles it, the editor does not guess. */
export function blankLine(overrides: Partial<LetterheadLine> = {}): LetterheadLine {
  return { text: '', text_bn: null, color: 'text', weight: 'normal', size: 1, transform: 'none', align: null, ...overrides };
}

/** A new footer column inherits the alignment its position implies — left, centre, right, reading across. */
export function blankColumn(index: number): LetterheadColumn {
  return { align: LETTERHEAD_ALIGNS[Math.min(index, 2)] ?? 'left', logo: false, lines: [] };
}

/**
 * The empty-but-valid letterhead — the client's copy of what `Letterhead::fromArray(null)` returns, down to the
 * `left` header alignment and the empty footer. The server always sends a normalised letterhead, so in practice
 * only tests and a pad row mid-flight reach this.
 */
export function emptyLetterhead(): Letterhead {
  return {
    accent_color: DEFAULT_ACCENT,
    text_color: DEFAULT_TEXT,
    muted_color: DEFAULT_MUTED,
    header: { align: 'left', lines: [], rule: true },
    footer: { columns: [], rule: true },
  };
}

/** Immutable swap; out-of-range targets return the list unchanged so a disabled button can still be clicked. */
export function moveItem<T>(items: T[], index: number, delta: number): T[] {
  const target = index + delta;
  if (target < 0 || target >= items.length) return items;
  const next = [...items];
  const moved = next[index];
  const swapped = next[target];
  if (moved === undefined || swapped === undefined) return items;
  next[index] = swapped;
  next[target] = moved;
  return next;
}

export function removeAt<T>(items: T[], index: number): T[] {
  return items.filter((_, i) => i !== index);
}

export function replaceAt<T>(items: T[], index: number, value: T): T[] {
  return items.map((item, i) => (i === index ? value : item));
}

/** Letterhead::hex() — anything that is not #RRGGBB is the fallback, never a broken CSS colour. */
export function hexOr(value: string | null | undefined, fallback: string): string {
  return typeof value === 'string' && /^#[0-9A-Fa-f]{6}$/.test(value) ? value.toUpperCase() : fallback;
}

export function clampSize(value: number): number {
  if (!Number.isFinite(value)) return 1;
  return Math.round(Math.min(SIZE_MAX, Math.max(SIZE_MIN, value)) * 100) / 100;
}

/** The three named colours resolved to real hex, with the same fallbacks the server applies. */
export function paletteOf(letterhead: Letterhead): Record<LetterheadColor, string> {
  return {
    accent: hexOr(letterhead.accent_color, DEFAULT_ACCENT),
    text: hexOr(letterhead.text_color, DEFAULT_TEXT),
    muted: hexOr(letterhead.muted_color, DEFAULT_MUTED),
  };
}

/**
 * One line's print styling. `size` is em so it scales with the pad's body size — the same relationship the print
 * CSS uses — and the colour is resolved through the pad's own three-colour palette, never hard-coded.
 */
export function lineSx(line: LetterheadLine, letterhead: Letterhead, fallbackAlign?: LetterheadAlign) {
  return {
    color: paletteOf(letterhead)[line.color],
    fontWeight: line.weight === 'bold' ? 700 : 400,
    fontSize: `${clampSize(line.size)}em`,
    textTransform: line.transform === 'uppercase' ? ('uppercase' as const) : ('none' as const),
    textAlign: (line.align ?? fallbackAlign ?? 'left') as LetterheadAlign,
    lineHeight: 1.25,
    letterSpacing: line.transform === 'uppercase' ? '.03em' : undefined,
  };
}

/**
 * What a line actually prints in this pad's language. `both` stacks the Bangla under the English (that is how a
 * bilingual pad reads); `bn` prefers the Bangla and falls back to the English rather than printing a blank line.
 */
export function lineText(line: LetterheadLine, language: 'bn' | 'en' | 'both'): { primary: string; secondary: string | null } {
  const bn = line.text_bn === null || line.text_bn.trim() === '' ? null : line.text_bn;
  if (language === 'en' || bn === null) return { primary: line.text, secondary: null };
  if (language === 'bn') return { primary: bn, secondary: null };
  return { primary: line.text, secondary: bn };
}

/** Letterhead::headerSide() — `split` is two columns; a line that says `right` goes right, the rest left. */
export function splitHeader(lines: LetterheadLine[]): { left: LetterheadLine[]; right: LetterheadLine[] } {
  return {
    left: lines.filter((line) => line.align !== 'right'),
    right: lines.filter((line) => line.align === 'right'),
  };
}
