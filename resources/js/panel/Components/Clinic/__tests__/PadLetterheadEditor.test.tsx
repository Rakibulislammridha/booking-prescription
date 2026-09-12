// The editor's claim is that it IS the preview: there is no second copy of the letterhead anywhere, so a line
// added, moved, deleted or recoloured on the left has to appear on the right in the same render. These tests
// drive the real controls and then read the sheet, rather than asserting against the editor's own state.
import { useState } from 'react';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { PadLetterheadEditor } from '../PadLetterheadEditor';
import { PadPreview } from '../PadPreview';
import { PadUnderlayControls } from '../PadUnderlayControls';
import { PAD_SECTIONS } from '@panel/lib/clinic/padGeometry';
import { blankLine, emptyLetterhead } from '@panel/lib/clinic/letterhead';
import type { Letterhead, LetterheadLine, PadSettings } from '@shared/types/models';

function line(text: string, overrides: Partial<LetterheadLine> = {}): LetterheadLine {
  return blankLine({ text, ...overrides });
}

function letterhead(overrides: Partial<Letterhead> = {}): Letterhead {
  return { ...emptyLetterhead(), ...overrides };
}

function pad(lh: Letterhead, overrides: Partial<PadSettings> = {}): PadSettings {
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
    letterhead: lh,
    token_slip_template: 'thermal_58',
    default_language: 'both',
    signature_path: null,
    sample_path: null,
    ...overrides,
  };
}

const PROFILE_DEFAULTS: Letterhead = letterhead({
  accent_color: '#004080',
  header: { align: 'center', lines: [line('DR. RAHMAN', { color: 'accent', weight: 'bold', size: 1.45, transform: 'uppercase' })], rule: true },
});

/** The page's own wiring, minus Inertia: one piece of state, the editor on it, the preview off it. */
function Harness({ initial, padOverrides }: { initial: Letterhead; padOverrides?: Partial<PadSettings> }) {
  const [value, setValue] = useState<Letterhead>(initial);
  const [shown, setShown] = useState(true);
  const [opacity, setOpacity] = useState(0.4);

  return (
    <>
      <PadLetterheadEditor value={value} onChange={setValue} onReset={() => setValue(PROFILE_DEFAULTS)} />
      <PadUnderlayControls shown={shown} opacity={opacity} isPdf={false} onShown={setShown} onOpacity={setOpacity} />
      <PadPreview
        pad={pad(value, padOverrides)}
        clinicName="Seba Hospital"
        doctorName="Dr. Rahman"
        degrees="MBBS, FCPS"
        bmdc="A-12345"
        logoUrl={null}
        signatureUrl={null}
        sampleUrl="/sample.png"
        sampleKind="image"
        sampleVisible={shown}
        sampleOpacity={opacity}
      />
    </>
  );
}

function headerLines(): string[] {
  return within(screen.getByTestId('pad-preview-letterhead'))
    .queryAllByTestId('pad-preview-letterhead-line')
    .map((node) => node.textContent ?? '');
}

describe('PadLetterheadEditor', () => {
  it('adds a header line and prints it in the preview', () => {
    render(<Harness initial={letterhead()} />);
    expect(headerLines()).toEqual([]);

    fireEvent.click(screen.getAllByRole('button', { name: 'Add a line' })[0] as HTMLElement);
    fireEvent.change(screen.getAllByLabelText('Line')[0] as HTMLElement, { target: { value: 'DR. RAHMAN' } });

    expect(headerLines()).toEqual(['DR. RAHMAN']);
    expect(screen.getByTestId('pad-preview-letterhead').dataset.lines).toBe('1');
  });

  it('reorders and deletes header lines, and the preview follows', () => {
    render(<Harness initial={letterhead({ header: { align: 'center', rule: true, lines: [line('First'), line('Second')] } })} />);
    expect(headerLines()).toEqual(['First', 'Second']);

    fireEvent.click(within(screen.getByTestId('letterhead-header-line-0')).getByRole('button', { name: 'Move down' }));
    expect(headerLines()).toEqual(['Second', 'First']);

    fireEvent.click(within(screen.getByTestId('letterhead-header-line-0')).getByRole('button', { name: 'Delete this line' }));
    expect(headerLines()).toEqual(['First']);
  });

  it('applies the accent colour picked for the pad to every accent line', () => {
    render(<Harness initial={letterhead({ header: { align: 'center', rule: true, lines: [line('DR. RAHMAN', { color: 'accent' })] } })} />);
    expect(within(screen.getByTestId('pad-preview-letterhead')).getAllByTestId('pad-preview-letterhead-line')[0]?.dataset.color).toBe('#B03A2E');

    fireEvent.change(screen.getByLabelText('Accent colour', { selector: 'input[type="color"]' }), { target: { value: '#004080' } });

    expect(within(screen.getByTestId('pad-preview-letterhead')).getAllByTestId('pad-preview-letterhead-line')[0]?.dataset.color).toBe('#004080');
  });

  it('builds footer columns, up to three, and draws them in the preview', () => {
    // `Letterhead::defaults()` ships no footer band at all, so the editor starts from nothing and adds.
    render(<Harness initial={letterhead()} />);
    expect(screen.queryByTestId('pad-preview-footer-columns')).toBeNull();

    const addColumn = screen.getByRole('button', { name: 'Add a column' });
    fireEvent.click(addColumn);
    expect(screen.getByTestId('pad-preview-footer-columns').dataset.columns).toBe('1');
    fireEvent.click(addColumn);
    fireEvent.click(addColumn);
    expect(screen.getByTestId('pad-preview-footer-columns').dataset.columns).toBe('3');
    expect(addColumn).toBeDisabled();

    // A line typed into the third column prints in the third column.
    fireEvent.click(within(screen.getByTestId('letterhead-column-2')).getByRole('button', { name: 'Add a line' }));
    fireEvent.change(within(screen.getByTestId('letterhead-column-2-line-0')).getAllByLabelText('Line')[0] as HTMLElement, { target: { value: '01711-000000' } });

    const columns = screen.getByTestId('pad-preview-footer-columns').children;
    expect(columns[2]?.textContent).toBe('01711-000000');
  });

  it('resets to the doctor’s own profile', () => {
    render(<Harness initial={letterhead({ header: { align: 'center', rule: true, lines: [line('Typed by hand')] } })} />);
    expect(headerLines()).toEqual(['Typed by hand']);

    fireEvent.click(screen.getByRole('button', { name: 'Reset to my profile' }));

    expect(headerLines()).toEqual(['DR. RAHMAN']);
  });

  it('prints the Bangla line under the English one when the pad prints both', () => {
    const bilingual = letterhead({ header: { align: 'center', rule: true, lines: [line('Chamber', { text_bn: 'চেম্বার' })] } });
    const { rerender } = render(<Harness initial={bilingual} />);
    expect(headerLines()).toEqual(['Chamberচেম্বার']);

    rerender(<Harness initial={bilingual} padOverrides={{ default_language: 'bn' }} />);
    expect(headerLines()).toEqual(['চেম্বার']);
  });

  it('drives the tracing underlay from the opacity control, and hides it when switched off', () => {
    render(<Harness initial={letterhead()} />);
    expect(screen.getByTestId('pad-preview-underlay').dataset.opacity).toBe('0.4');

    fireEvent.change(screen.getByRole('slider'), { target: { value: '0.85' } });
    expect(screen.getByTestId('pad-preview-underlay').dataset.opacity).toBe('0.85');

    fireEvent.click(screen.getByLabelText('Show it under the preview'));
    expect(screen.queryByTestId('pad-preview-underlay')).toBeNull();
  });

  it('draws both rules in the colour the print partials draw them in', () => {
    // `partials/{header,footer}.blade.php` both set `border-top-color: $lh->textColor`. The preview used to pick
    // the accent, which looked better on screen and was a lie about the paper.
    render(<Harness initial={letterhead({ text_color: '#203040', accent_color: '#B03A2E', header: { align: 'center', rule: true, lines: [line('Name')] } })} />);
    expect(screen.getByTestId('pad-preview-header-rule').dataset.ruleColor).toBe('#203040');

    fireEvent.click(screen.getByRole('button', { name: 'Add a column' }));
    expect(screen.getByTestId('pad-preview-footer-columns').dataset.ruleColor).toBe('#203040');
  });

  it('keeps the preview on the renderer’s own geometry while the letterhead changes', () => {
    render(<Harness initial={letterhead()} padOverrides={{ paper_size: 'A4', margins: { top: 25, right: 10, bottom: 15, left: 20 }, font_size_pt: 11.5 }} />);

    const sheet = screen.getByTestId('pad-preview-sheet');
    expect(sheet.dataset.widthMm).toBe('210');
    expect(sheet.dataset.heightMm).toBe('297');
    expect(sheet.dataset.marginsMm).toBe('25 10 15 20');
    expect(sheet.dataset.fontSizePt).toBe('11.5');
  });

  it('clamps a line size to the bounds the validator enforces', () => {
    render(<Harness initial={letterhead({ header: { align: 'center', rule: true, lines: [line('Name')] } })} />);
    const size = within(screen.getByTestId('letterhead-header-line-0')).getByRole('spinbutton', { name: 'Size (×)' });

    fireEvent.change(size, { target: { value: '9' } });
    expect((size as HTMLInputElement).value).toBe('2');

    fireEvent.change(size, { target: { value: '0.1' } });
    expect((size as HTMLInputElement).value).toBe('0.7');
  });
});
