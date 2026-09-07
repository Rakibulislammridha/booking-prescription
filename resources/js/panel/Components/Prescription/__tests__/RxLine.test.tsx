// The keyboard model of §1.3 exercised through the real component: `nap` ⏎ picks the drug, the shorthand is parsed
// and read back inline, ⏎ opens the next line, an error keeps focus on the line, Backspace at column 0 reopens the
// chip. This is the two-drug routine path — no mouse anywhere in it.
import { describe, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { DrugSearchHit } from '@shared/types/models';
import { WriterStoreProvider, useWriter, useWriterStoreApi } from '@panel/hooks/prescription/useWriterStore';
import { RxSection } from '../RxSection';
import { manualTimers, writerProps } from '@panel/lib/prescription/__tests__/fixtures';

const HIT: DrugSearchHit = {
  kind: 'presentation',
  strength: '500 mg',
  id: 's1234',
  source: 'master',
  doc_type: 'presentation',
  label: 'Napa 500 mg Tab',
  generic_id: 17,
  generic_name: 'Paracetamol',
  generic_aliases: [],
  brand_id: 88,
  custom_brand_id: null,
  brand_name: 'Napa',
  manufacturer: 'Beximco',
  strength_id: 1234,
  strength_label: '500 mg',
  strength_value: 500,
  strength_unit: 'mg',
  per_volume_ml: null,
  strength_mg: 500,
  per_ml: null,
  dosage_form_id: 3,
  form: 'Tablet',
  form_code: 'tab',
  default_unit: 'tab',
  route_id: 1,
  route: 'Oral',
  route_code: 'po',
  pack_size: null,
  pack_size_value: null,
  pack_unit: null,
  info_slug: 'paracetamol',
  therapeutic_class: null,
  is_controlled: false,
  popularity: 90,
  is_active: true,
  review_status: null,
  promoted_to_master: null,
  usage: 42,
  fav_for_dx: true,
  score: 1187.5,
  last_shorthand: '1+0+1 5d af',
};

const CUSTOM: DrugSearchHit = { ...HIT, id: 'c55', source: 'custom', brand_id: null, custom_brand_id: 55, brand_name: 'Clinic Para', strength_id: null, review_status: 'pending', score: 812, last_shorthand: null };

vi.mock('@panel/api/prescription', async () => {
  const actual = await vi.importActual<typeof import('@panel/api/prescription')>('@panel/api/prescription');
  return {
    ...actual,
    searchDrugs: vi.fn(async (q: string) => ({ q, took_ms: 3, hits: q.startsWith('na') ? [HIT, CUSTOM] : [], engine: 'database' as const })),
    saveDraft: vi.fn(async () => ({ prescription: { ...writerProps().prescription }, alerts: [], issue_blocked_by: [] })),
    checkSafety: vi.fn(async () => ({ alerts: [], issue_blocked_by: [], computed: { items: {} }, catalog_version: 'v' })),
  };
});

function Harness({ onStore }: { onStore?: (api: ReturnType<typeof useWriterStoreApi>) => void }) {
  const store = useWriterStoreApi();
  onStore?.(store);
  const state = useWriter((s) => s); // subscribe: the store is the single source of truth for the lines
  return (
    <RxSection
      items={state.items}
      alerts={state.alerts}
      dxCodes={['J06.9']}
      lang="en"
      focusKey={undefined}
      onNextZone={() => undefined}
      onCheatsheet={() => undefined}
      onSaveTemplate={() => undefined}
      onFocusAlert={() => undefined}
      registerInsert={() => undefined}
    />
  );
}

function setup() {
  let store: ReturnType<typeof useWriterStoreApi> | null = null;
  const timers = manualTimers();
  const props = writerProps();
  const utils = render(
    <WriterStoreProvider props={props} options={{ timers: timers.api }}>
      <Harness onStore={(s) => (store = s)} />
    </WriterStoreProvider>,
  );
  const api = store as unknown as ReturnType<typeof useWriterStoreApi>;
  act(() => {
    api.getState().addItem();
  });
  return { store: api, timers, utils };
}

describe('RxLine keyboard', () => {
  it('finds a drug, distinguishes a clinic custom brand, and commits it with Enter', async () => {
    const { store } = setup();
    const input = screen.getByLabelText('Type a drug name');
    fireEvent.change(input, { target: { value: 'nap' } });

    await waitFor(() => expect(screen.getByRole('listbox')).toBeInTheDocument());
    expect(screen.getByText('Napa')).toBeInTheDocument();
    expect(screen.getByText('clinic')).toBeInTheDocument(); // the custom brand is visually distinguished
    expect(screen.getByText(/review pending/)).toBeInTheDocument();

    fireEvent.keyDown(input, { key: 'Enter' });
    await waitFor(() => expect(store.getState().items[0]?.drug?.brand_name).toBe('Napa'));
  });

  it('moves the highlight with the arrows before selecting', async () => {
    const { store } = setup();
    const input = screen.getByLabelText('Type a drug name');
    fireEvent.change(input, { target: { value: 'nap' } });
    await waitFor(() => expect(screen.getByRole('listbox')).toBeInTheDocument());

    fireEvent.keyDown(input, { key: 'ArrowDown' });
    fireEvent.keyDown(input, { key: 'Enter' });
    await waitFor(() => expect(store.getState().items[0]?.drug?.custom_brand_id).toBe(55));
  });

  it('parses the shorthand inline and never guesses silently', async () => {
    const { store } = setup();
    const key = store.getState().items[0]?.key ?? '';
    store.getState().setDrug(key, {
      kind: 'presentation', generic_id: 17, brand_id: 88, custom_brand_id: null, strength_id: 1234,
      generic_name: 'Paracetamol', brand_name: 'Napa', strength: '500 mg', form: 'Tablet', form_code: 'tab', route: 'Oral', route_code: 'po', strength_mg: 500,
    });

    const dose = await screen.findByLabelText('Dose shorthand');
    fireEvent.change(dose, { target: { value: '1+0+1 10d af' } });
    await waitFor(() => expect(screen.getByTestId('rx-interpretation')).toHaveTextContent('20 tab'));

    fireEvent.change(dose, { target: { value: '1+0+1 10d aff' } });
    await waitFor(() => expect(screen.getByTestId('rx-error')).toHaveTextContent('Unknown token "aff"'));
    expect(screen.getByText('Did you mean af?')).toBeInTheDocument();
    expect(screen.queryByTestId('rx-interpretation')).not.toBeInTheDocument();
  });

  it('Enter on a clean line opens the next one; on a line in error it stays put', async () => {
    const { store } = setup();
    const key = store.getState().items[0]?.key ?? '';
    store.getState().setDrug(key, { kind: 'generic', generic_id: 17, brand_id: null, custom_brand_id: null, strength_id: null, generic_name: 'Paracetamol', brand_name: null, strength: null, form: null, form_code: 'tab', route: null, strength_mg: 500 });
    const dose = await screen.findByLabelText('Dose shorthand');

    fireEvent.change(dose, { target: { value: '1+0+1 10d aff' } });
    fireEvent.keyDown(dose, { key: 'Enter' });
    expect(store.getState().items).toHaveLength(1);

    fireEvent.change(dose, { target: { value: '1+0+1 10d af' } });
    fireEvent.keyDown(dose, { key: 'Enter' });
    await waitFor(() => expect(store.getState().items).toHaveLength(2));
  });

  it('Backspace at column 0 reopens the drug chip, Ctrl+D duplicates and Ctrl+Backspace deletes', async () => {
    const { store } = setup();
    const key = store.getState().items[0]?.key ?? '';
    store.getState().setDrug(key, { kind: 'generic', generic_id: 17, brand_id: null, custom_brand_id: null, strength_id: null, generic_name: 'Paracetamol', brand_name: null, strength: null, form: null, form_code: 'tab', route: null, strength_mg: 500 });
    const dose = await screen.findByLabelText('Dose shorthand');
    fireEvent.change(dose, { target: { value: '1+0+1 5d' } });

    fireEvent.keyDown(dose, { key: 'd', ctrlKey: true });
    await waitFor(() => expect(store.getState().items).toHaveLength(2));

    fireEvent.keyDown(dose, { key: 'Backspace', ctrlKey: true });
    await waitFor(() => expect(store.getState().items).toHaveLength(1));

    const dose2 = await screen.findByLabelText('Dose shorthand');
    Object.defineProperty(dose2, 'selectionStart', { value: 0, configurable: true });
    fireEvent.keyDown(dose2, { key: 'Backspace' });
    await waitFor(() => expect(store.getState().items[0]?.drug).toBeNull());
  });
});
