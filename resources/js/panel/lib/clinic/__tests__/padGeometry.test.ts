import { describe, expect, it } from 'vitest';
import {
  DEFAULT_MARGINS,
  PAD_SECTIONS,
  padFontFamily,
  padFontSizePt,
  padFooterHeightMm,
  padGeometry,
  padHeaderHeightMm,
  padMargins,
  padOrderedSections,
  padRxFontSizePt,
} from '../padGeometry';
import type { PadSettings } from '@shared/types/models';

// These expectations are the numbers App\Domain\Prescription\Render\PadGeometry produces (PRESCRIPTION.md §7.2).
// If the renderer's clamps ever move, this file fails before a doctor discovers it on paper.
function pad(overrides: Partial<PadSettings> = {}): PadSettings {
  return {
    paper_size: 'A5',
    orientation: 'portrait',
    letterhead_enabled: true,
    preprinted_mode: false,
    logo_path: null,
    header_html: null,
    footer_html: null,
    margins: { ...DEFAULT_MARGINS },
    header_height_mm: 35,
    footer_height_mm: 20,
    font_family: 'Noto Sans Bengali',
    font_size_pt: 10.5,
    show_qr: true,
    show_vitals: true,
    show_drug_info_url: true,
    layout: {
      sections: PAD_SECTIONS.map((key) => ({ key, visible: true })),
      columns: 1,
      rx_font_size_pt: null,
      flags: { icd_codes: true, investigation_prices: true, generic_names: true },
    },
    token_slip_template: 'thermal_58',
    default_language: 'both',
    signature_path: null,
    ...overrides,
  };
}

describe('padGeometry — mirror of PHP PadGeometry', () => {
  it('uses the ISO 216 paper table and swaps the edges in landscape', () => {
    expect(padGeometry(pad({ paper_size: 'A4' }))).toMatchObject({ paperWidthMm: 210, paperHeightMm: 297 });
    expect(padGeometry(pad({ paper_size: 'A5' }))).toMatchObject({ paperWidthMm: 148, paperHeightMm: 210 });
    expect(padGeometry(pad({ paper_size: 'A4', orientation: 'landscape' }))).toMatchObject({ paperWidthMm: 297, paperHeightMm: 210 });
  });

  it('derives the printable box from paper minus margins, floored at 20mm', () => {
    const g = padGeometry(pad({ paper_size: 'A4', margins: { top: 25, right: 10, bottom: 15, left: 20 } }));
    expect(g.contentWidthMm).toBe(180);
    expect(g.contentHeightMm).toBe(257);

    const squeezed = padGeometry(pad({ paper_size: 'A5', margins: { top: 60, right: 60, bottom: 60, left: 60 } }));
    expect(squeezed.contentWidthMm).toBe(28);
    expect(squeezed.contentHeightMm).toBe(90);
  });

  it('clamps margins to 0…60 and falls back per side', () => {
    expect(padMargins({ top: -5, right: 99, bottom: 12, left: 0 })).toEqual({ top: 0, right: 60, bottom: 12, left: 0 });
    expect(padMargins(undefined)).toEqual(DEFAULT_MARGINS);
  });

  it('clamps the preprinted header band to 0…120 and the footer band to the renderer’s 0…80', () => {
    expect(padHeaderHeightMm(200)).toBe(120);
    expect(padHeaderHeightMm(-1)).toBe(0);
    expect(padHeaderHeightMm(null)).toBe(35);
    expect(padFooterHeightMm(120)).toBe(80);
    expect(padFooterHeightMm(null)).toBe(20);
  });

  it('reserves the footer band only in preprinted mode, and preprinted always wins over the letterhead', () => {
    expect(padGeometry(pad({ preprinted_mode: false, footer_height_mm: 25 })).reservedFooterMm).toBe(0);
    const preprinted = padGeometry(pad({ preprinted_mode: true, letterhead_enabled: true, footer_height_mm: 25 }));
    expect(preprinted.reservedFooterMm).toBe(25);
    expect(preprinted.letterhead).toBe(false);
  });

  it('replaces an out-of-range body font size with 10.5 rather than clamping it', () => {
    expect(padFontSizePt(9)).toBe(9);
    expect(padFontSizePt(24)).toBe(10.5);
    expect(padFontSizePt(2)).toBe(10.5);
    expect(padFontSizePt(null)).toBe(10.5);
  });

  it('falls back to body + 0.5 for the Rx font size', () => {
    expect(padRxFontSizePt(null, 10.5)).toBe(11);
    expect(padRxFontSizePt(13, 10.5)).toBe(13);
    expect(padRxFontSizePt(40, 10.5)).toBe(11);
  });

  it('rejects a font family that is not plainly a font name', () => {
    expect(padFontFamily('Inter')).toBe('Inter');
    expect(padFontFamily('Noto Sans Bengali')).toBe('Noto Sans Bengali');
    expect(padFontFamily('evil"; body{display:none}')).toBe('Noto Sans Bengali');
    expect(padFontFamily('')).toBe('Noto Sans Bengali');
  });

  it('keeps the doctor’s section order, drops hidden ones, and prints everything when the list is empty', () => {
    expect(padOrderedSections([{ key: 'rx', visible: true }, { key: 'vitals', visible: false }, { key: 'advice', visible: true }]))
      .toEqual(['rx', 'advice']);
    expect(padOrderedSections([])).toEqual(PAD_SECTIONS);
    expect(padOrderedSections(undefined)).toEqual(PAD_SECTIONS);
  });
});
