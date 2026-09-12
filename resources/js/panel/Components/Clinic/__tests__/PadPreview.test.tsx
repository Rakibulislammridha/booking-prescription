// The preview's whole job is to be the print. These assertions pin the two things a doctor aligns a physical
// pre-printed pad against: that the blank band is exactly `header_height_mm` and that the sheet is the real paper
// size in millimetres — the same numbers PadGeometry writes into `@page` and `.header-spacer`.
import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { PadPreview } from '../PadPreview';
import { PAD_SECTIONS } from '@panel/lib/clinic/padGeometry';
import { emptyLetterhead } from '@panel/lib/clinic/letterhead';
import type { PadSettings } from '@shared/types/models';

function pad(overrides: Partial<PadSettings> = {}): PadSettings {
  return {
    paper_size: 'A5',
    orientation: 'portrait',
    letterhead_enabled: true,
    preprinted_mode: false,
    logo_path: null,
    header_html: null,
    footer_html: null,
    margins: { top: 20, right: 15, bottom: 20, left: 15 },
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
    letterhead: emptyLetterhead(),
    token_slip_template: 'thermal_58',
    default_language: 'both',
    signature_path: null,
    sample_path: null,
    ...overrides,
  };
}

const props = {
  clinicName: 'Seba Hospital',
  doctorName: 'Dr. Rahman',
  degrees: 'MBBS, FCPS',
  bmdc: 'A-12345',
  logoUrl: null,
  signatureUrl: null,
};

describe('PadPreview', () => {
  it('draws the letterhead when preprinted mode is off', () => {
    // A pad whose letterhead has never been designed still prints: the block falls back to the three facts the
    // server would have seeded it with (PadLetterhead::defaults) rather than leaving the top of the sheet blank.
    render(<PadPreview pad={pad()} {...props} />);

    // (The signature block carries the doctor's name too, so scope the look-up to the letterhead.)
    const block = screen.getByTestId('pad-preview-letterhead');
    expect(within(block).getByText('Dr. Rahman')).toBeTruthy();
    expect(within(block).getByText('MBBS, FCPS')).toBeTruthy();
    expect(screen.queryByTestId('pad-preview-blank-band')).toBeNull();
  });

  it('prints the designed letterhead lines instead, once there are any', () => {
    const designed = {
      ...emptyLetterhead(),
      accent_color: '#004080',
      header: {
        align: 'center' as const,
        rule: true,
        lines: [
          { text: 'PROF. DR. A RAHMAN', text_bn: null, color: 'accent' as const, weight: 'bold' as const, size: 1.45, transform: 'uppercase' as const, align: null },
          { text: 'MBBS (DMC), FCPS (Medicine)', text_bn: null, color: 'text' as const, weight: 'normal' as const, size: .95, transform: 'none' as const, align: null },
        ],
      },
    };

    render(<PadPreview pad={pad({ letterhead: designed })} {...props} />);

    const lines = screen.getAllByTestId('pad-preview-letterhead-line');
    expect(lines.map((node) => node.textContent)).toEqual(['PROF. DR. A RAHMAN', 'MBBS (DMC), FCPS (Medicine)']);
    expect(lines[0]?.dataset.color).toBe('#004080');
    expect(screen.getByTestId('pad-preview-header-rule')).toBeTruthy();
  });

  it('replaces the letterhead with a blank band of exactly header_height_mm in preprinted mode', () => {
    render(<PadPreview pad={pad({ preprinted_mode: true, header_height_mm: 42, footer_height_mm: 18 })} {...props} />);

    expect(screen.getByTestId('pad-preview-blank-band').dataset.bandMm).toBe('42');
    expect(screen.queryByText('Seba Hospital')).toBeNull();
    // Preprinted pads keep their own footer area clear too (PadGeometry::reservedFooterMm).
    expect(screen.getByTestId('pad-preview-footer-band').dataset.bandMm).toBe('18');
  });

  it('lays the sheet out at the real paper size, with the margins as padding', () => {
    render(<PadPreview pad={pad({ paper_size: 'A4', margins: { top: 25, right: 10, bottom: 15, left: 20 } })} {...props} />);

    const sheet = screen.getByTestId('pad-preview-sheet');
    expect(sheet.dataset.paper).toBe('A4');
    expect(sheet.dataset.widthMm).toBe('210');
    expect(sheet.dataset.heightMm).toBe('297');
    expect(sheet.dataset.marginsMm).toBe('25 10 15 20');
    expect(sheet.dataset.fontSizePt).toBe('10.5');
  });

  it('honours the layout flags the renderer honours', () => {
    const { rerender } = render(<PadPreview pad={pad()} {...props} />);
    expect(screen.getByText('Paracetamol')).toBeTruthy();

    rerender(<PadPreview pad={pad({ layout: { ...pad().layout, flags: { icd_codes: false, investigation_prices: false, generic_names: false } } })} {...props} />);
    expect(screen.queryByText('Paracetamol')).toBeNull();
  });
});
