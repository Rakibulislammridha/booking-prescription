// The shell's answer to a refusal — ONE handler, registered once by panel/app.tsx for every panel and console
// screen. It lives here rather than in the entry so it can be tested against Inertia's real event plumbing;
// app.tsx imports fonts and calls createInertiaApp at module scope and cannot be loaded under vitest.
//
// Inertia softens nothing by itself. A navigation that comes back 403 is not an Inertia response, so
// `handleNonInertiaResponse` ends in `dialog.show(response.data)` (@inertiajs/core): Laravel's error page inside
// a full-screen black modal, in English, over whatever the person was doing — and there is no
// resources/views/errors/ behind it to soften either. A compounder met that on at least three paths (the drawer's
// Prescriptions entry, the board's "open doctor screen", a colleague's sheet), and the only exit is the browser's
// Back button, which on a kiosked reception tablet may not be on screen at all.
import { router } from '@inertiajs/react';
import { useHttpNotice } from '@panel/hooks/shell/useHttpNotice';

/**
 * `httpException` is cancelable, so the modal is suppressed for exactly the two statuses a member of staff can do
 * something about, and the reason is handed to the shell (useHttpNotice → PanelLayout's banner):
 *
 *   403  the screen is not theirs. Deliberately NO redirect: a refused visit never swaps the page, so Inertia has
 *        already left them exactly where they were — on a screen they can use — and a bounce to the dashboard to
 *        say "no" would throw away a half-registered patient or a half-counted cash drawer. Being refused a door
 *        is not a reason to lose the room.
 *   419  the token no longer matches the session, so nothing typed from here can be saved. The banner carries the
 *        "sign in again" button rather than reloading by itself: a reload under the hands of whoever is typing is
 *        its own small outage. The idle timeout — the ordinary way a session ends in this app — never arrives as
 *        a 419 at all; EnforceIdleTimeout redirects to the login screen with its own message.
 *
 * Every other status keeps Inertia's modal ON PURPOSE: a 500 is for whoever is being paged, a 402 renders the
 * Suspended page, and swallowing either would hide a fault behind a polite sentence.
 *
 * Returns the unsubscribe for the three listeners, which the entry does not need and a test does.
 */
export function watchRefusals(): () => void {
  const offException = router.on('httpException', (event) => {
    const status = event.detail.response.status;
    const kind = status === 403 ? 'forbidden' : status === 419 ? 'session_expired' : null;

    if (kind === null) return;

    event.preventDefault();
    useHttpNotice.getState().show(kind);
  });

  // The banner lasts until the user gets somewhere they may go. Both events for the reason
  // syncSharedOnNavigate gives (@shared/inertia): neither covers every visit on its own — `navigate` is skipped
  // when Inertia replaces the history entry, `success` never fires for back/forward. A refused visit fires
  // NEITHER, which is what makes this the right pair: it cannot clear the message it was just given.
  const offNavigate = router.on('navigate', () => useHttpNotice.getState().clear());
  const offSuccess = router.on('success', () => useHttpNotice.getState().clear());

  return () => { offException(); offNavigate(); offSuccess(); };
}
