// The live pad preview (PRESCRIPTION.md §1.1, §7.1). What matters here is not how it looks but WHERE it gets the
// sheet from and how often: it must load `panel.prescription.print` — the same route the printer gets — once per
// saved draft version, behind a trailing debounce, into a frame that cannot run the sheet's own window.print().
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { act, render, screen } from '@testing-library/react';
import { PadPreviewPane, PREVIEW_DEBOUNCE_MS } from '../PadPreviewPane';

const SHEET = '<!DOCTYPE html><html><body><div class="sheet"><div class="sheet-body">Napa 500 mg</div></div></body></html>';

// The Ziggy config is a runtime prop, so the route table is stubbed by name — which is exactly what this file has
// to pin down: the preview resolves `panel.prescription.print` and nothing else.
vi.mock('@shared/routes', () => ({
  route: (name: string, params: { prescription: string }) => `/routes/${name}/${params.prescription}`,
}));

vi.mock('@panel/api/prescription', async () => {
  const actual = await vi.importActual<typeof import('@panel/api/prescription')>('@panel/api/prescription');
  return { ...actual, fetchPrintHtml: vi.fn(async () => SHEET) };
});

const { fetchPrintHtml, printUrl } = await import('@panel/api/prescription');
const fetched = vi.mocked(fetchPrintHtml);

function pane(version: string, pending = false) {
  return <PadPreviewPane prescriptionId="rx-1" version={version} paper="A4" pending={pending} onClose={() => undefined} />;
}

async function tick(ms: number): Promise<void> {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(ms);
  });
}

describe('PadPreviewPane', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    fetched.mockClear();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('loads the print route for the draft — the one markup source there is', () => {
    // If this name ever changes, the preview stops being the printed sheet and starts being a second opinion.
    expect(printUrl('rx-1')).toBe('/routes/panel.prescription.print/rx-1');
  });

  it('waits out the debounce, then paints the sheet into a frame that cannot script', async () => {
    render(pane('v1'));

    await tick(PREVIEW_DEBOUNCE_MS - 50);
    expect(fetched).not.toHaveBeenCalled();                        // still typing

    await tick(100);
    expect(fetched).toHaveBeenCalledTimes(1);
    expect(fetched.mock.calls[0]?.[0]).toBe('rx-1');

    const frame = screen.getByTestId('pad-preview-frame');
    expect(frame).toHaveAttribute('srcdoc', SHEET);
    // No allow-scripts: the print route's own one-click window.print() (§7.6) must never fire inside the writer.
    expect(frame.getAttribute('sandbox')).toBe('allow-same-origin');
  });

  it('fetches once per saved version and not again for one it is already showing', async () => {
    const view = render(pane('v1'));
    await tick(PREVIEW_DEBOUNCE_MS);
    expect(fetched).toHaveBeenCalledTimes(1);

    // A re-render with the same saved draft (the doctor moved the caret, an alert arrived) is not a new sheet.
    view.rerender(pane('v1', true));
    await tick(PREVIEW_DEBOUNCE_MS * 2);
    expect(fetched).toHaveBeenCalledTimes(1);

    view.rerender(pane('v2'));
    await tick(PREVIEW_DEBOUNCE_MS);
    expect(fetched).toHaveBeenCalledTimes(2);
  });

  it('collapses a burst of saves into the request that follows the last one', async () => {
    const view = render(pane('v1'));
    for (const version of ['v2', 'v3', 'v4']) {
      await tick(PREVIEW_DEBOUNCE_MS / 4);
      view.rerender(pane(version));
    }
    await tick(PREVIEW_DEBOUNCE_MS);

    expect(fetched).toHaveBeenCalledTimes(1);
  });

  it('says so while the writer still has an unsaved edit', async () => {
    render(pane('v1', true));
    await tick(PREVIEW_DEBOUNCE_MS);

    expect(screen.getByTestId('pad-preview-pending')).toBeInTheDocument();
  });

  it('offers a retry instead of an empty pane when the sheet cannot be loaded', async () => {
    fetched.mockRejectedValueOnce(new Error('offline'));
    render(pane('v1'));
    await tick(PREVIEW_DEBOUNCE_MS);

    expect(screen.getByText('The preview could not load.')).toBeInTheDocument();
  });
});
