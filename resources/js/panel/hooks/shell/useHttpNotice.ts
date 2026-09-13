// What the shell says when the SERVER refuses a navigation, and where that sentence is kept while it is said.
//
// Inertia's own answer to a non-Inertia error response is `dialog.show(response.data)` (@inertiajs/core,
// `handleNonInertiaResponse`): the raw response painted into a full-screen black modal. For a 500 on a
// developer's machine that is the most useful form the stack trace has — for a compounder whose drawer offered a
// screen their role may not open it is a wall of English error chrome over the desk they were working, with no
// way out but the browser's Back button. There is no resources/views/errors/ behind it to soften either.
//
// panel/app.tsx cancels that modal for the two statuses a member of staff can actually act on and writes the
// reason here instead; PanelLayout renders it above the page. A store rather than a shared prop because a refused
// visit NEVER becomes a page: nothing new arrives from the server, so the only thing that can carry the message
// is the client that saw the refusal.
import { create } from 'zustand';

/**
 * `forbidden` — 403: this screen is not this user's. Either a role's gate or DoctorScope's (the page exists, it is
 * simply someone else's doctor's), and in both cases the answer is the same sentence plus "ask your admin".
 * `session_expired` — 419: the CSRF token no longer matches the session. NOT the idle timeout, which redirects to
 * the login screen with its own message (EnforceIdleTimeout::expire) and never reaches here.
 */
export type HttpNoticeKind = 'forbidden' | 'session_expired';

export interface HttpNoticeState {
  kind: HttpNoticeKind | null;
  show(kind: HttpNoticeKind): void;
  clear(): void;
}

export const useHttpNotice = create<HttpNoticeState>((set) => ({
  kind: null,
  show: (kind) => set({ kind }),
  // Returning the state object itself when there is nothing to clear is what makes the reset-on-every-navigation
  // in app.tsx free: zustand compares by identity, so no subscriber is notified and the shell does not re-render
  // once per visit for a banner that was never up.
  clear: () => set((state) => (state.kind === null ? state : { kind: null })),
}));
