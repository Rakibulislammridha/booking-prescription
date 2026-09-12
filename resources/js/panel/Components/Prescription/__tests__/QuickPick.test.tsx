// The right pane's job in one file (PRESCRIPTION.md §1.3, §3.5): a routine drug is ONE click or ONE keystroke, it
// arrives with the dose this doctor last used for it, that dose is parsed against the real presentation on the
// FIRST render, and the caret lands in the dose field ready to change it without touching the mouse.
import { describe, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen } from '@testing-library/react';
import type { TopDrug } from '@shared/types/models';
import { WriterStoreProvider, useWriter, useWriterStoreApi } from '@panel/hooks/prescription/useWriterStore';
import { insertTopDrug } from '@panel/lib/prescription/quickPick';
import { manualTimers, writerProps } from '@panel/lib/prescription/__tests__/fixtures';
import { QuickPickPane } from '../QuickPickPane';
import { RxSection } from '../RxSection';

vi.mock('@panel/api/prescription', async () => {
  const actual = await vi.importActual<typeof import('@panel/api/prescription')>('@panel/api/prescription');
  return {
    ...actual,
    searchDrugs: vi.fn(async (q: string) => ({ q, took_ms: 1, hits: [], engine: 'database' as const })),
    fetchFavourites: vi.fn(async () => []),
    saveDraft: vi.fn(async () => ({ prescription: { ...writerProps().prescription }, alerts: [], issue_blocked_by: [] })),
    checkSafety: vi.fn(async () => ({ alerts: [], issue_blocked_by: [], computed: { items: {} }, catalog_version: 'v' })),
  };
});

/** A learned favourite exactly as `DoctorLearningCache::favouriteRow()` sends it: ids AND presentation. */
const SYRUP: TopDrug = {
  id: 11,
  icd10_code: null,
  drug: {
    kind: 'presentation',
    presentation_key: 's4321',
    generic_id: 17,
    brand_id: 88,
    custom_brand_id: null,
    strength_id: 4321,
    generic_name: 'Paracetamol',
    brand_name: 'Napa Syrup',
    strength: '120 mg/5 ml',
    form: 'Syrup',
    form_code: 'syr',
    default_unit: 'ml',
    route: 'Oral',
    route_code: 'po',
    pack_size: 100,
    pack_unit: 'ml',
    strength_mg: 120,
    per_ml: 24,
  },
  label: 'Napa Syrup 120 mg/5 ml',
  default_dose: { dose_schedule: '2+0+2', duration_days: 7, timing: 'after', instruction: null, shorthand: '2+0+2 7d af' },
  use_count: 12,
  is_pinned: false,
  rank: 0,
  last_used_at: null,
};

const TABLET: TopDrug = {
  ...SYRUP,
  id: 12,
  drug: { ...SYRUP.drug, presentation_key: 's1234', strength_id: 1234, brand_name: 'Napa', strength: '500 mg', form: 'Tablet', form_code: 'tab', default_unit: 'tab', per_ml: null, strength_mg: 500, pack_unit: 'tab' },
  label: 'Napa 500 mg Tab',
  default_dose: { dose_schedule: '1+0+1', duration_days: 5, timing: 'after', instruction: null, shorthand: '1+0+1 5d af' },
};

function Harness() {
  const store = useWriterStoreApi();
  const state = useWriter((s) => s);
  return (
    <>
      <QuickPickPane
        topDrugs={[SYRUP, TABLET]}
        templates={[]}
        snippets={[]}
        investigations={[]}
        dxCodes={[]}
        onInsertDrug={(row) => insertTopDrug(store, row)}
        onApplyTemplate={() => undefined}
        onInsertInvestigation={() => undefined}
        onInsertAdvice={() => undefined}
        onEscape={() => undefined}
      />
      <RxSection
        items={state.items}
        alerts={state.alerts}
        dxCodes={[]}
        lang="en"
        focus={state.focus}
        onNextZone={() => undefined}
        onCheatsheet={() => undefined}
        onSaveTemplate={() => undefined}
        onFocusAlert={() => undefined}
        registerInsert={() => undefined}
      />
    </>
  );
}

function setup() {
  let store: ReturnType<typeof useWriterStoreApi> | null = null;
  const timers = manualTimers();
  function Capture() {
    store = useWriterStoreApi();
    return null;
  }
  render(
    <WriterStoreProvider props={writerProps()} options={{ timers: timers.api }}>
      <Capture />
      <Harness />
    </WriterStoreProvider>,
  );
  const api = store as unknown as ReturnType<typeof useWriterStoreApi>;
  act(() => {
    api.getState().addItem(undefined, undefined, { focus: false });
  });
  return { store: api, timers };
}

/** The store's focus request is dispatched through a 0 ms timeout (RxSection), so let the DOM catch up. */
async function settle(): Promise<void> {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 0));
  });
}

describe('quick pick → a written line', () => {
  it('inserts the drug with the dose the doctor last used, parsed against the real presentation', async () => {
    const { store } = setup();

    fireEvent.click(screen.getByText('Napa Syrup 120 mg/5 ml'));
    await settle();

    const item = store.getState().items[0]!;
    expect(item.drug?.strength_id).toBe(4321);
    expect(item.shorthand).toBe('2+0+2 7d af');
    // The point of carrying the presentation: a syrup is measured in ml on the FIRST parse. Without form_code /
    // default_unit this reads "tab" until the server echo replaces it — the ~400 ms flicker on every insert.
    expect(item.parsed?.unit).toBe('ml');
    expect(item.parsed?.quantity.unit).not.toBe('tab');
    expect(screen.getByTestId('rx-interpretation').textContent).toContain('ml');
  });

  it('leaves the caret in the dose field with the last dose selected, so one keystroke changes it', async () => {
    const { store } = setup();

    fireEvent.click(screen.getByText('Napa 500 mg Tab'));
    await settle();

    const dose = screen.getByLabelText('Dose shorthand') as HTMLInputElement;
    expect(document.activeElement).toBe(dose);
    expect(dose.value).toBe('1+0+1 5d af');
    expect([dose.selectionStart, dose.selectionEnd]).toEqual([0, dose.value.length]);

    // Typing over the selection is the whole point: the dose changes without reaching for the mouse.
    fireEvent.change(dose, { target: { value: '1+1+1 3d af' } });
    expect(store.getState().items[0]!.shorthand).toBe('1+1+1 3d af');
    expect(store.getState().items[0]!.parsed?.schedule).not.toBeNull();

    // …and ⏎ still commits the line and opens the next one (§1.3).
    fireEvent.keyDown(dose, { key: 'Enter' });
    expect(store.getState().items).toHaveLength(2);
    expect(store.getState().focus.itemKey).toBe(store.getState().items[1]!.key);
  });

  it('fills the empty line the writer keeps at the bottom instead of stacking blank ones', async () => {
    const { store } = setup();

    fireEvent.click(screen.getByText('Napa 500 mg Tab'));
    await settle();

    expect(store.getState().items).toHaveLength(1);
  });
});

describe('QuickPickPane keyboard', () => {
  it('adds the highlighted row with ↑/↓ and ⏎ from the search box', async () => {
    const { store } = setup();
    const search = screen.getByLabelText('Search (Ctrl+K)');

    fireEvent.keyDown(search, { key: 'ArrowDown' });          // second row: the tablet
    fireEvent.keyDown(search, { key: 'Enter' });
    await settle();

    expect(store.getState().items[0]!.drug?.strength_id).toBe(1234);
    expect(store.getState().items[0]!.shorthand).toBe('1+0+1 5d af');
  });

  it('adds the first match of what was typed, so Ctrl+K nap ⏎ is a whole line', async () => {
    const { store } = setup();
    const search = screen.getByLabelText('Search (Ctrl+K)');

    fireEvent.change(search, { target: { value: 'syrup' } });
    expect(screen.queryByText('Napa 500 mg Tab')).not.toBeInTheDocument();

    fireEvent.keyDown(search, { key: 'Enter' });
    await settle();

    expect(store.getState().items[0]!.drug?.strength_id).toBe(4321);
  });

  it('shows the last dose on every row — it is what the doctor scans for', () => {
    setup();

    expect(screen.getByText('2+0+2 7d af')).toBeInTheDocument();
    expect(screen.getByText('1+0+1 5d af')).toBeInTheDocument();
  });
});
