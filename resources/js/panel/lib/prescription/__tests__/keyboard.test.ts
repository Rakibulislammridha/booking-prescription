// The documented keyboard model of PRESCRIPTION.md §1.3 as a table test: if this file is green the common path
// (add drug → dose → next drug → issue) never needs the mouse.
import { describe, expect, it } from 'vitest';
import { ALT_ZONES, nextZone, resolveGlobalKey, resolveRxLineKey, ZONE_ORDER, type KeyEventLike, type RxLineContext } from '../keyboard';

function key(k: string, mods: Partial<KeyEventLike> = {}): KeyEventLike {
  return { key: k, ctrlKey: false, metaKey: false, shiftKey: false, altKey: false, ...mods };
}

function ctx(overrides: Partial<RxLineContext> = {}): RxLineContext {
  return { popupOpen: false, phase: 'dose', caret: 4, length: 4, hasGhost: false, hasError: false, dirty: false, hasAlert: false, isLast: false, ...overrides };
}

describe('zone order', () => {
  it('walks complaints → findings → diagnosis → rx → investigations → advice → follow-up → referral → issue', () => {
    expect(ZONE_ORDER).toEqual(['complaints', 'findings', 'diagnosis', 'rx', 'investigations', 'advice', 'follow_up', 'referral', 'issue']);
    expect(ZONE_ORDER).not.toContain('vitals'); // compounder data: Alt+2 or a click only
    expect(nextZone('rx', 1)).toBe('investigations');
    expect(nextZone('complaints', -1)).toBe('complaints');
    expect(nextZone('issue', 1)).toBe('issue');
  });

  it('reaches vitals only through Alt+2', () => {
    expect(ALT_ZONES[1]).toBe('vitals');
    expect(resolveGlobalKey(key('2', { altKey: true }), { inRightPane: false, dialogOpen: false })).toEqual({ type: 'zone', zone: 'vitals' });
    expect(resolveGlobalKey(key('5', { altKey: true }), { inRightPane: false, dialogOpen: false })).toEqual({ type: 'zone', zone: 'rx' });
    expect(resolveGlobalKey(key('9', { altKey: true }), { inRightPane: false, dialogOpen: false })).toBeNull();
  });
});

describe('global shortcuts', () => {
  const plain = { inRightPane: false, dialogOpen: false };

  it('maps the documented table and nothing else', () => {
    expect(resolveGlobalKey(key('Enter', { ctrlKey: true }), plain)).toEqual({ type: 'issue_dialog' });
    expect(resolveGlobalKey(key('k', { ctrlKey: true }), plain)).toEqual({ type: 'quick_pick' });
    expect(resolveGlobalKey(key('K', { metaKey: true }), plain)).toEqual({ type: 'quick_pick' });
    expect(resolveGlobalKey(key('p', { ctrlKey: true }), plain)).toEqual({ type: 'print_preview' });
    expect(resolveGlobalKey(key('F2'), plain)).toEqual({ type: 'cheatsheet' });
    expect(resolveGlobalKey(key('/', { ctrlKey: true }), plain)).toEqual({ type: 'cheatsheet' });
    expect(resolveGlobalKey(key('h', { ctrlKey: true }), plain)).toEqual({ type: 'handwriting' });
    expect(resolveGlobalKey(key('V', { ctrlKey: true, shiftKey: true }), plain)).toEqual({ type: 'dictation' });
    expect(resolveGlobalKey(key('a'), plain)).toBeNull();
    expect(resolveGlobalKey(key('Enter'), plain)).toBeNull();
  });

  it('sends Esc back to the Rx pane only from the right pane or a dialog', () => {
    expect(resolveGlobalKey(key('Escape'), plain)).toBeNull();
    expect(resolveGlobalKey(key('Escape'), { inRightPane: true, dialogOpen: false })).toEqual({ type: 'back_to_rx' });
    expect(resolveGlobalKey(key('Escape'), { inRightPane: false, dialogOpen: true })).toEqual({ type: 'back_to_rx' });
  });
});

describe('Rx line keys', () => {
  it('drives the drug popup with the arrows and commits with Enter', () => {
    expect(resolveRxLineKey(key('ArrowDown'), ctx({ popupOpen: true, phase: 'drug' }))).toEqual({ type: 'popup_move', delta: 1 });
    expect(resolveRxLineKey(key('ArrowUp'), ctx({ popupOpen: true, phase: 'drug' }))).toEqual({ type: 'popup_move', delta: -1 });
    expect(resolveRxLineKey(key('Enter'), ctx({ popupOpen: true, phase: 'drug' }))).toEqual({ type: 'popup_select' });
    expect(resolveRxLineKey(key('Escape'), ctx({ popupOpen: true, phase: 'drug' }))).toEqual({ type: 'popup_close' });
  });

  it('Enter in the dose phase commits and opens the next line — unless the line is in error', () => {
    expect(resolveRxLineKey(key('Enter'), ctx())).toEqual({ type: 'commit_and_new' });
    expect(resolveRxLineKey(key('Enter'), ctx({ hasError: true }))).toEqual({ type: 'commit_and_stay' });
    expect(resolveRxLineKey(key('Enter', { shiftKey: true }), ctx())).toEqual({ type: 'commit_and_stay' });
    expect(resolveRxLineKey(key('Enter', { ctrlKey: true }), ctx())).toBeNull(); // Ctrl+Enter belongs to the page
  });

  it('Tab commits and moves on, and is left alone while the line is in error', () => {
    expect(resolveRxLineKey(key('Tab'), ctx())).toEqual({ type: 'commit_and_next' });
    expect(resolveRxLineKey(key('Tab'), ctx({ hasError: true }))).toBeNull();
    expect(resolveRxLineKey(key('Tab', { shiftKey: true }), ctx())).toBeNull();
  });

  it('moves between lines with the arrows and reorders with Ctrl', () => {
    expect(resolveRxLineKey(key('ArrowDown'), ctx())).toEqual({ type: 'move_line', delta: 1 });
    expect(resolveRxLineKey(key('ArrowUp'), ctx())).toEqual({ type: 'move_line', delta: -1 });
    expect(resolveRxLineKey(key('ArrowUp', { ctrlKey: true }), ctx())).toEqual({ type: 'reorder', delta: -1 });
    expect(resolveRxLineKey(key('ArrowDown', { ctrlKey: true }), ctx())).toEqual({ type: 'reorder', delta: 1 });
  });

  it('Backspace at column 0 reopens the drug chip; Ctrl+Backspace deletes the line', () => {
    expect(resolveRxLineKey(key('Backspace'), ctx({ caret: 0 }))).toEqual({ type: 'edit_drug' });
    expect(resolveRxLineKey(key('Backspace'), ctx({ caret: 3 }))).toBeNull();
    expect(resolveRxLineKey(key('Backspace', { ctrlKey: true }), ctx({ caret: 3 }))).toEqual({ type: 'delete' });
  });

  it('accepts the last_shorthand ghost with → at the end of the field', () => {
    expect(resolveRxLineKey(key('ArrowRight'), ctx({ hasGhost: true, caret: 0, length: 0 }))).toEqual({ type: 'accept_ghost' });
    expect(resolveRxLineKey(key('ArrowRight'), ctx({ hasGhost: true, caret: 2, length: 6 }))).toBeNull();
    expect(resolveRxLineKey(key('ArrowRight'), ctx({ hasGhost: false }))).toBeNull();
  });

  it('Esc reverts a dirty line, duplicates with Ctrl+D and focuses the alert with Ctrl+.', () => {
    expect(resolveRxLineKey(key('Escape'), ctx({ dirty: true }))).toEqual({ type: 'revert' });
    expect(resolveRxLineKey(key('Escape'), ctx({ dirty: false }))).toBeNull();
    expect(resolveRxLineKey(key('d', { ctrlKey: true }), ctx())).toEqual({ type: 'duplicate' });
    expect(resolveRxLineKey(key('.', { ctrlKey: true }), ctx({ hasAlert: true }))).toEqual({ type: 'focus_alert' });
    expect(resolveRxLineKey(key('.', { ctrlKey: true }), ctx({ hasAlert: false }))).toBeNull();
  });

  it('never swallows an ordinary keystroke', () => {
    for (const k of ['a', '1', '+', ' ', '/']) expect(resolveRxLineKey(key(k), ctx())).toBeNull();
  });
});
