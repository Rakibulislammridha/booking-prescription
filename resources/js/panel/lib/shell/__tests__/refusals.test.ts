// The global refusal handler, against Inertia's real event plumbing: these tests dispatch the very
// `inertia:httpException` CustomEvent that @inertiajs/core fires before it paints Laravel's error page into a
// full-screen modal, and assert both halves of the fix — the modal is CANCELLED (defaultPrevented) and the shell
// is told what happened. A typo in the event name, or a callback that forgets to cancel, fails here; nothing else
// in the suite can see it, because the modal is drawn deep inside the router's response handler.
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { useHttpNotice } from '@panel/hooks/shell/useHttpNotice';
import { watchRefusals } from '../refusals';

let stop: () => void;

/** Exactly `fireHttpExceptionEvent(response)` from @inertiajs/core: cancelable, the response in `detail`. */
function httpException(status: number): boolean {
  const event = new CustomEvent('inertia:httpException', {
    cancelable: true,
    detail: { response: { status, data: '<html>Laravel error page</html>', headers: {} } },
  });

  document.dispatchEvent(event);

  return event.defaultPrevented;
}

beforeEach(() => {
  useHttpNotice.setState({ kind: null });
  stop = watchRefusals();
});

afterEach(() => stop());

describe('watchRefusals', () => {
  it('cancels the error modal for a 403 and hands the shell the reason', () => {
    expect(httpException(403)).toBe(true);
    expect(useHttpNotice.getState().kind).toBe('forbidden');
  });

  it('does the same for an expired token, with its own message', () => {
    expect(httpException(419)).toBe(true);
    expect(useHttpNotice.getState().kind).toBe('session_expired');
  });

  // The narrowness IS the design: a 500 must still land in front of whoever can fix it, and a 402 is the
  // Suspended page. Swallowing either would hide a fault behind a polite sentence.
  it.each([400, 402, 404, 500, 503])('leaves %i to Inertia, modal and all', (status) => {
    expect(httpException(status)).toBe(false);
    expect(useHttpNotice.getState().kind).toBeNull();
  });

  it('clears the banner once the user reaches a page they may open', () => {
    httpException(403);

    document.dispatchEvent(new CustomEvent('inertia:navigate', { detail: { page: {} } }));

    expect(useHttpNotice.getState().kind).toBeNull();
  });

  // `success` as well as `navigate`: Inertia skips `navigate` when it replaces the history entry, which is what
  // PATCH /locale → redirect back to the same URL does.
  it('also clears on a successful visit that replaces the history entry', () => {
    httpException(419);

    document.dispatchEvent(new CustomEvent('inertia:success', { detail: { page: {} } }));

    expect(useHttpNotice.getState().kind).toBeNull();
  });

  it('stops listening when the shell unsubscribes', () => {
    stop();

    expect(httpException(403)).toBe(false);
    expect(useHttpNotice.getState().kind).toBeNull();
  });
});
