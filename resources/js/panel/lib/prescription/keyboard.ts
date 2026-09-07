// The writer's keyboard model (PRESCRIPTION.md §1.3), as pure resolvers so it can be tested without a DOM and so
// every component agrees on one table. Nothing here touches state: the caller maps an action onto the store.
//
// FOCUS ORDER (Tab / Shift+Tab): complaints → findings → diagnosis → rx[0..n] → investigations → advice →
// follow-up → referral → issue. Vitals is deliberately OUT of the Tab order (compounder data — Alt+2 or a click).
// The right pane is never in the Tab order: Ctrl+K enters it, Esc returns to the last Rx line.
import type { FocusZone } from './store/writerStore';

export const ZONE_ORDER: FocusZone[] = ['complaints', 'findings', 'diagnosis', 'rx', 'investigations', 'advice', 'follow_up', 'referral', 'issue'];

/** Alt+1..8 (Alt+2 is the only way into Vitals from the keyboard). */
export const ALT_ZONES: FocusZone[] = ['complaints', 'vitals', 'findings', 'diagnosis', 'rx', 'investigations', 'advice', 'follow_up'];

export interface KeyEventLike {
  key: string;
  ctrlKey: boolean;
  metaKey: boolean;
  shiftKey: boolean;
  altKey: boolean;
}

export type GlobalAction =
  | { type: 'zone'; zone: FocusZone }
  | { type: 'quick_pick' }
  | { type: 'issue_dialog' }
  | { type: 'print_preview' }
  | { type: 'cheatsheet' }
  | { type: 'dictation' }
  | { type: 'handwriting' }
  | { type: 'back_to_rx' };

export type RxLineAction =
  | { type: 'popup_move'; delta: number }
  | { type: 'popup_select' }
  | { type: 'popup_close' }
  | { type: 'commit_and_new' }
  | { type: 'commit_and_stay' }
  | { type: 'commit_and_next' }
  | { type: 'move_line'; delta: number }
  | { type: 'reorder'; delta: number }
  | { type: 'duplicate' }
  | { type: 'delete' }
  | { type: 'edit_drug' }
  | { type: 'accept_ghost' }
  | { type: 'revert' }
  | { type: 'focus_alert' };

function mod(event: KeyEventLike): boolean {
  return event.ctrlKey || event.metaKey;
}

export function nextZone(zone: FocusZone, delta: number): FocusZone {
  const index = ZONE_ORDER.indexOf(zone);
  if (index === -1) return delta > 0 ? 'rx' : 'complaints';
  const next = Math.min(Math.max(index + delta, 0), ZONE_ORDER.length - 1);
  return ZONE_ORDER[next] ?? zone;
}

/** Window-level shortcuts. Returns null when the key is not ours (so typing is never swallowed). */
export function resolveGlobalKey(event: KeyEventLike, context: { inRightPane: boolean; dialogOpen: boolean }): GlobalAction | null {
  if (event.key === 'F2' || (mod(event) && event.key === '/')) return { type: 'cheatsheet' };
  if (mod(event) && event.key === 'Enter') return { type: 'issue_dialog' };
  if (mod(event) && (event.key === 'k' || event.key === 'K')) return { type: 'quick_pick' };
  if (mod(event) && (event.key === 'p' || event.key === 'P')) return { type: 'print_preview' };
  if (mod(event) && (event.key === 'h' || event.key === 'H') && !event.shiftKey) return { type: 'handwriting' };
  if (mod(event) && event.shiftKey && (event.key === 'v' || event.key === 'V')) return { type: 'dictation' };
  if (event.altKey && !mod(event) && /^[1-8]$/.test(event.key)) {
    const zone = ALT_ZONES[Number(event.key) - 1];
    return zone === undefined ? null : { type: 'zone', zone };
  }
  if (event.key === 'Escape' && (context.inRightPane || context.dialogOpen)) return { type: 'back_to_rx' };
  return null;
}

export interface RxLineContext {
  /** The autocomplete popup is open (drug phase or a template/snippet palette). */
  popupOpen: boolean;
  /** 'drug' before a chip is committed, 'dose' after. */
  phase: 'drug' | 'dose';
  /** Caret column inside the dose field. */
  caret: number;
  /** Length of the dose text — ArrowRight at the end accepts the ghost suggestion. */
  length: number;
  /** A `last_shorthand` ghost is on offer. */
  hasGhost: boolean;
  /** The line has an error issue: Enter must not create a new line. */
  hasError: boolean;
  /** The line has an unsaved edit — Esc reverts it. */
  dirty: boolean;
  /** This line carries a safety alert (Ctrl+. focuses it). */
  hasAlert: boolean;
  isLast: boolean;
}

/** One Rx line's keys. Returns null when the key should reach the input unchanged. */
export function resolveRxLineKey(event: KeyEventLike, context: RxLineContext): RxLineAction | null {
  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    const delta = event.key === 'ArrowDown' ? 1 : -1;
    if (context.popupOpen) return { type: 'popup_move', delta };
    if (mod(event)) return { type: 'reorder', delta };
    return { type: 'move_line', delta };
  }

  if (event.key === 'Enter') {
    if (mod(event)) return null; // Ctrl+Enter is global (issue)
    if (context.popupOpen) return { type: 'popup_select' };
    if (context.phase === 'drug') return null; // nothing highlighted: let the field keep the text
    if (event.shiftKey) return { type: 'commit_and_stay' };
    return context.hasError ? { type: 'commit_and_stay' } : { type: 'commit_and_new' };
  }

  if (event.key === 'Tab' && !event.shiftKey && context.phase === 'dose' && !context.popupOpen) {
    return context.hasError ? null : { type: 'commit_and_next' };
  }

  if (event.key === 'Escape') {
    if (context.popupOpen) return { type: 'popup_close' };
    return context.dirty ? { type: 'revert' } : null;
  }

  if (event.key === 'Backspace') {
    if (mod(event)) return { type: 'delete' };
    if (context.phase === 'dose' && context.caret === 0) return { type: 'edit_drug' };
    return null;
  }

  if (event.key === 'ArrowRight' && context.hasGhost && context.caret >= context.length && !context.popupOpen) return { type: 'accept_ghost' };
  if (mod(event) && (event.key === 'd' || event.key === 'D')) return { type: 'duplicate' };
  if (mod(event) && event.key === '.' && context.hasAlert) return { type: 'focus_alert' };

  return null;
}
