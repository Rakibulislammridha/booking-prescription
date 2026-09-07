// The pad designer's preview is only worth having if it is the print. This module is a line-for-line mirror of
// `App\Domain\Prescription\Render\PadGeometry` (PRESCRIPTION.md §7.2) — the same paper table, the same clamps, the
// same fallbacks, in the same order — so the millimetres the preview draws are the millimetres
// `resources/views/print/prescription/layout.blade.php` writes into `@page`, `.header-spacer` and `.sheet`.
//
// Two rules keep the mirror honest:
//   1. every bound here also arrives from the server as `limits` (PadDesignerController::LIMITS) and the inputs are
//      clamped to it, so a value the renderer would quietly pull back cannot be entered in the first place;
//   2. `padGeometry.test.ts` asserts the table below against the numbers PadGeometry documents.
import type { PadMargins, PadSectionKey, PadSettings } from '@shared/types/models';

/** ISO 216 short/long edge in mm — PadGeometry::shortEdge()/longEdge(). */
const PAPER_MM: Record<'A4' | 'A5', { short: number; long: number }> = {
  A4: { short: 210, long: 297 },
  A5: { short: 148, long: 210 },
};

export const DEFAULT_MARGINS: PadMargins = { top: 20, right: 15, bottom: 20, left: 15 };

export const PAD_SECTIONS: PadSectionKey[] = [
  'vitals', 'complaints', 'examination', 'diagnosis', 'rx',
  'investigations', 'advice', 'followup', 'referral', 'signature',
];

/** CSS reference pixels per millimetre: 96dpi / 25.4. The preview scales this, never guesses it. */
export const PX_PER_MM = 96 / 25.4;

export interface PadGeometry {
  paperWidthMm: number;
  paperHeightMm: number;
  margins: PadMargins;
  contentWidthMm: number;
  contentHeightMm: number;
  headerHeightMm: number;
  footerHeightMm: number;
  reservedFooterMm: number;
  fontFamily: string;
  fontSizePt: number;
  rxFontSizePt: number;
  columns: 1 | 2;
  sections: PadSectionKey[];
  preprinted: boolean;
  letterhead: boolean;
}

function clamp(value: number, min: number, max: number): number {
  return Math.max(min, Math.min(max, value));
}

/** PadGeometry::margins() — non-numeric falls back to the default for that side, then clamps to 0…60. */
export function padMargins(margins: Partial<PadMargins> | null | undefined): PadMargins {
  const out = { ...DEFAULT_MARGINS };
  (Object.keys(DEFAULT_MARGINS) as (keyof PadMargins)[]).forEach((side) => {
    const raw = margins?.[side];
    const value = typeof raw === 'number' && Number.isFinite(raw) ? Math.round(raw) : DEFAULT_MARGINS[side];
    out[side] = clamp(value, 0, 60);
  });
  return out;
}

/** PadGeometry::headerHeightMm() — 0…120, default 35. */
export function padHeaderHeightMm(value: number | null | undefined): number {
  return clamp(Math.trunc(Number.isFinite(value) ? Number(value) : 35), 0, 120);
}

/** PadGeometry::footerHeightMm() — 0…80, default 20. Note the renderer's ceiling is 80, not the column's 120. */
export function padFooterHeightMm(value: number | null | undefined): number {
  return clamp(Math.trunc(Number.isFinite(value) ? Number(value) : 20), 0, 80);
}

/** PadGeometry::fontSizePt() — anything outside 6…18 is not clamped but *replaced* by 10.5, exactly as in PHP. */
export function padFontSizePt(value: number | null | undefined): number {
  const size = Number(value);
  return Number.isFinite(size) && size >= 6 && size <= 18 ? size : 10.5;
}

/** PadGeometry::rxFontSizePt() — the Rx table's own size, or body + 0.5 when unset/out of range. */
export function padRxFontSizePt(value: number | null | undefined, fontSizePt: number): number {
  const size = Number(value);
  return value !== null && value !== undefined && Number.isFinite(size) && size >= 6 && size <= 20 ? size : fontSizePt + 0.5;
}

/** PadGeometry::fontFamily() — a font name or nothing; anything else renders as the Bangla-capable default. */
export function padFontFamily(value: string | null | undefined): string {
  const family = (value ?? '').trim();
  return /^[A-Za-z0-9][A-Za-z0-9 -]{0,63}$/.test(family) ? family : 'Noto Sans Bengali';
}

/** PadGeometry::orderedSections() — the doctor's own order, visible only; an empty list means "print them all". */
export function padOrderedSections(sections: { key: PadSectionKey; visible: boolean }[] | undefined): PadSectionKey[] {
  const ordered = (sections ?? [])
    .filter((s) => PAD_SECTIONS.includes(s.key) && s.visible !== false)
    .map((s) => s.key);
  const unique = Array.from(new Set(ordered));
  return unique.length === 0 ? [...PAD_SECTIONS] : unique;
}

/** Everything the preview needs, derived the way the renderer derives it. */
export function padGeometry(pad: PadSettings): PadGeometry {
  const paper = PAPER_MM[pad.paper_size === 'A5' ? 'A5' : 'A4'];
  const landscape = pad.orientation === 'landscape';
  const paperWidthMm = landscape ? paper.long : paper.short;
  const paperHeightMm = landscape ? paper.short : paper.long;
  const margins = padMargins(pad.margins);
  const fontSizePt = padFontSizePt(pad.font_size_pt);
  const preprinted = pad.preprinted_mode === true;
  const footerHeightMm = padFooterHeightMm(pad.footer_height_mm);

  return {
    paperWidthMm,
    paperHeightMm,
    margins,
    contentWidthMm: Math.max(20, paperWidthMm - margins.left - margins.right),
    contentHeightMm: Math.max(20, paperHeightMm - margins.top - margins.bottom),
    headerHeightMm: padHeaderHeightMm(pad.header_height_mm),
    footerHeightMm,
    reservedFooterMm: preprinted ? footerHeightMm : 0,
    fontFamily: padFontFamily(pad.font_family),
    fontSizePt,
    rxFontSizePt: padRxFontSizePt(pad.layout?.rx_font_size_pt ?? null, fontSizePt),
    columns: pad.layout?.columns === 2 ? 2 : 1,
    sections: padOrderedSections(pad.layout?.sections),
    // UpdateDoctorPadSettings forces letterhead_enabled off whenever preprinted_mode is on: the doctor's own
    // letterhead is already on the paper, and the header partial reserves the blank band instead.
    preprinted,
    letterhead: preprinted ? false : pad.letterhead_enabled !== false,
  };
}

/** PadGeometry::flag() — an absent flag prints (SCHEMA §3.1). */
export function padFlag(pad: PadSettings, key: 'icd_codes' | 'investigation_prices' | 'generic_names'): boolean {
  return pad.layout?.flags?.[key] !== false;
}
