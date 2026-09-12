// One click — or one keystroke — from the right pane to a written Rx line (PRESCRIPTION.md §1.3 "insert top-50
// drug (1)", §3.5). Lives here rather than in the page so the path the doctor actually uses is the path the tests
// drive: pick the row, get the drug AND the dose he last prescribed for it, with the caret already in the dose
// field ready to change it.
import type { TopDrug } from '@shared/types/models';
import { drugFromTop } from './context';
import type { WriterStore } from './store/writerStore';

export function insertTopDrug(store: WriterStore, row: TopDrug): string {
  const state = store.getState();
  // The writer always keeps one empty line at the bottom; fill that one rather than pushing a second blank below it.
  const empty = state.items.find((item) => item.drug === null && item.shorthand === '');
  const key = empty?.key ?? state.addItem(undefined, undefined, { focus: false });

  // The row carries the whole presentation (§3.5), so this line parses against the real form on its FIRST render —
  // no tablet-default parse for the server echo to correct half a second later.
  state.setDrug(key, drugFromTop(row));
  const dose = row.default_dose.shorthand ?? '';
  if (dose !== '') state.setShorthand(key, dose);

  // Straight into the dose field with the last dose SELECTED: the click inserted the line, one keystroke replaces
  // the dose, Enter keeps it and opens the next line. No mouse after the pick.
  state.setFocus('rx', key, dose === '' ? 'dose' : 'dose_all');

  return key;
}
